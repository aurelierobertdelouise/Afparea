// Point d'entrée pour les hébergements cPanel (« Setup Node.js App » / Passenger),
// qui chargent le fichier de démarrage avec require() : on relaie vers le serveur en modules ES.
import('./server/index.js').catch((error) => {
  console.error(error);
  process.exit(1);
});
