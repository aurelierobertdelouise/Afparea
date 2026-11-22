<?php
declare(strict_types=1);

/** Envoi d'un e-mail texte UTF-8 via la fonction mail() de PHP (opérationnelle sur LWS). */
function send_mail(string $to, string $subject, string $body): bool
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL) || !function_exists('mail')) {
        return false;
    }
    $from = (string) cfg('mail_from', 'no-reply@afpar.com');
    $name = '=?UTF-8?B?' . base64_encode((string) cfg('mail_from_name', cfg('app_name'))) . '?=';
    $headers = [
        'From' => "$name <$from>",
        'Reply-To' => $from,
        'MIME-Version' => '1.0',
        'Content-Type' => 'text/plain; charset=UTF-8',
        'Content-Transfer-Encoding' => '8bit',
        'X-Mailer' => 'Gourmandises-AFPAR',
    ];
    $subject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    try {
        return @mail($to, $subject, $body, $headers, '-f' . $from);
    } catch (Throwable $e) {
        error_log('[gourmandises] mail : ' . $e->getMessage());
        return false;
    }
}
