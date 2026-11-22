<?php
declare(strict_types=1);

$page = get('p');

if ($page === 'deconnexion') {
    logout();
    session_start();
    flash('success', 'Vous êtes déconnecté·e.');
    redirect(url('connexion'));
}

// ------------------------------------------- Onboarding (1re connexion, §7.1)
if ($page === 'bienvenue') {
    $u = current_user();
    if (!$u) {
        redirect(url('connexion'));
    }
    if (profile_complete($u)) {
        redirect(url());
    }
    $errors = [];
    $data = [
        'prenom' => $u['prenom'], 'nom' => $u['nom'], 'fonction' => $u['fonction'],
        'telephone' => $u['telephone'], 'centre_id' => (string) $u['centre_id'],
    ];
    if (is_post()) {
        csrf_check();
        foreach (array_keys($data) as $k) {
            $data[$k] = mb_substr(post($k), 0, 120);
        }
        if ($data['prenom'] === '') { $errors['prenom'] = 'Indiquez votre prénom.'; }
        if ($data['nom'] === '') { $errors['nom'] = 'Indiquez votre nom.'; }
        if (!centre((int) $data['centre_id'])) { $errors['centre_id'] = 'Choisissez votre centre de rattachement.'; }
        if ($data['telephone'] !== '' && !preg_match('/^\+?[0-9 .\-]{9,20}$/', $data['telephone'])) { $errors['telephone'] = 'Numéro invalide.'; }
        if (empty($_POST['consent'])) { $errors['consent'] = 'Vous devez prendre connaissance de l\'information RGPD pour continuer.'; }
        if (!$errors) {
            db()->prepare('UPDATE users SET prenom=?, nom=?, fonction=?, telephone=?, centre_id=?, consent_at=? WHERE id=?')
                ->execute([$data['prenom'], mb_strtoupper($data['nom']), $data['fonction'], $data['telephone'], (int) $data['centre_id'], date('Y-m-d H:i:s'), $u['id']]);
            flash('success', 'Bienvenue ' . $data['prenom'] . ' ! Votre profil est prêt.');
            $back = $_SESSION['after_login'] ?? null;
            unset($_SESSION['after_login']);
            redirect(safe_back($back, url()));
        }
    }
    render('bienvenue', ['title' => 'Bienvenue', 'u' => $u, 'data' => $data, 'errors' => $errors], 'auth_layout');
    exit;
}

// -------------------------------------------------- Connexion par code PIN
if (current_user()) {
    redirect(url());
}
$step = isset($_SESSION['otp_email']) ? 'code' : 'email';
$error = null;

if (is_post()) {
    csrf_check();
    $action = post('action');
    if ($action === 'email') {
        $email = normalize_email(post('email'));
        $error = otp_send($email);
        if (!$error) {
            $_SESSION['otp_email'] = $email;
            $step = 'code';
        }
    } elseif ($action === 'code' && isset($_SESSION['otp_email'])) {
        $error = otp_verify($_SESSION['otp_email'], post('code'));
        if (!$error) {
            $u = current_user();
            if (!profile_complete($u)) {
                redirect(url('bienvenue'));
            }
            $back = $_SESSION['after_login'] ?? null;
            unset($_SESSION['after_login']);
            redirect(safe_back($back, url()));
        }
    } elseif ($action === 'renvoyer' && isset($_SESSION['otp_email'])) {
        $error = otp_send($_SESSION['otp_email']);
        if (!$error) {
            flash('success', 'Un nouveau code vous a été envoyé.');
            redirect(url('connexion'));
        }
    } elseif ($action === 'changer') {
        unset($_SESSION['otp_email'], $_SESSION['otp_debug_code']);
        $step = 'email';
    }
}

render('connexion', [
    'title' => 'Connexion',
    'step' => $step,
    'error' => $error,
    'email' => $_SESSION['otp_email'] ?? '',
    'debugCode' => cfg('otp_debug') ? ($_SESSION['otp_debug_code'] ?? null) : null,
], 'auth_layout');
