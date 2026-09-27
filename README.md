# DevEnv Doctor

Diagnostic de la chaine de developpement locale : PHP, Apache, base de donnees,
fichiers, outils CLI et reseau — en **36 tests automatises**, sans aucune dependance
a installer.

L'outil ne se contente pas de dire « ca marche » : chaque echec affiche le
**correctif exact** a appliquer (fichier, directive, ligne).

```
  Sante 87/100   |   24 OK  6 warn  1 echec  3 ignore  |  12 175 ms

 A CORRIGER
  1. Connexion mysqli
     La connexion TCP passe mais le serveur ne repond pas au handshake : il est
     probablement bloque (CPU a 100%) ou casse...
```

## Demarrage rapide

### Dans un terminal

```bash
C:\xampp\htdocs\devcheck\bin\doctor.cmd
```

Affiche le rapport en couleur. Options utiles :

| Option | Effet |
|---|---|
| `--only=db` | Limite a une categorie : `web`, `db`, `fs`, `tools`, `net` |
| `--json` | Sortie JSON (pour script ou CI) |
| `--fail` | Code de sortie `1` s'il y a au moins un echec (utile en intégration continue) |
| `--no-color` | Désactive les couleurs ANSI |

### Dans le navigateur

```bash
C:\xampp\htdocs\devcheck\bin\serve.cmd
```

Puis <http://localhost:8080>.

L'interface permet de filtrer par mot-clé, de masquer les OK, d'exporter en JSON
(`/?export=json`) et d'imprimer le rapport.

> Le serveur intégré de PHP (`php -S`) est utilisé volontairement : il fonctionne
> même si Apache est cassé, ce qui permet de diagnostiquer précisément ce qui ne va pas.
> Une fois Apache réparé, le projet fonctionne aussi en le déposant dans un sous-dossier
> du `DocumentRoot`.

## Ce qui est testé

| Catégorie | Tests |
|---|---|
| **Serveur & PHP** | Moteur (SAPI : Apache/mod_php, CLI, FPM), version, extensions critiques, extensions recommandées, `php.ini` (memory, upload, timeouts), sessions, fuseau horaire, contexte web, `display_errors`, cohérence du `php.ini` analysé/chargé |
| **Base de données** | Ouverture du port TCP, connexion `mysqli`, **self-test CRUD complet** (CREATE DATABASE, CREATE TABLE, INSERT préparé, SELECT, UPDATE, DELETE, DROP), transactions et rollback InnoDB |
| **Fichiers & droits** | Création du dossier de stockage, cycle écriture → lecture → suppression, fichier de 2 Mo, dossier temporaire, droits sur le document root, présence de `php.ini` / `httpd.conf` |
| **Outils CLI** | Node, npm, git, Composer, PHP CLI, VS Code, Python, client MySQL — version réelle exécutée, chemin, présence dans le PATH, détection des alias vides Microsoft Store, `composer diagnose`, composants XAMPP, entrées PATH mortes |
| **Réseau** | Ports locaux ouverts/fermés, résolution du nom d'hôte, DNS externe, accès HTTPS sortant |

## Niveaux de statut

| Statut | Signification |
|---|---|
| **OK** | Conforme |
| **Avertissement** | Non bloquant mais à corriger avant de produire |
| **Echec** | Bloquant : impossible de développer correctement |
| **Ignore** | Test non applicable à la configuration (ex. `$_SERVER` en CLI) |
| **Info** | Contexte, sans judgement |

Le score `/100` pondère les échecs à 1 et les avertissements à 0,5.

## Configuration

Tout est dans `config.php`, surchargeable par variables d'environnement (pratique
pour tester un autre poste sans toucher au fichier) :

| Variable | Défaut | Rôle |
|---|---|---|
| `DEVCHECK_DB_HOST` | `127.0.0.1` | Hôte MySQL |
| `DEVCHECK_DB_PORT` | `3306` | Port MySQL |
| `DEVCHECK_DB_USER` | `root` | Utilisateur |
| `DEVCHECK_DB_PASS` | *(vide)* | Mot de passe |
| `DEVCHECK_DB_NAME` | `devcheck_db` | Base de test |
| `DEVCHECK_XAMPP_ROOT` | `%SystemDrive%\xampp` | Racine XAMPP |
| `DEVCHECK_PHP_INI` | `<xampp>\php\php.ini` | `php.ini` à analyser |

Exemple pour une base avec mot de passe :

```bash
set DEVCHECK_DB_PASS=secret && bin\doctor.cmd
```

> **Le self-test crée et supprime** la base `devcheck_db` et ses tables. Aucune
> donnée existante n'est touchée. Passer `keep_database => true` dans `config.php`
> pour conserver la base de test.

## Structure du projet

```
devcheck/
├── index.php              interface web (autonome, sans framework)
├── config.php             configuration
├── composer.json          métadonnées + scripts (`composer check`, `composer serve`)
├── bin/
│   ├── doctor.php         moteur du rapport CLI
│   ├── doctor.cmd         lanceur Windows
│   └── serve.cmd          serveur web local
├── src/
│   ├── autoload.php       autoloader PSR-4 minimal (Composer optionnel)
│   ├── Doctor.php         orchestrateur des suites
│   ├── Report.php         agrégation, score, sérialisation
│   ├── Result.php         objet résultat (statut, valeur, correctif)
│   ├── Support.php        PATH, exécutables, ports, formats
│   ├── PhpChecks.php      suite « Serveur & PHP »
│   ├── DbChecks.php       suite « Base de données »
│   ├── FsChecks.php       suite « Fichiers & droits »
│   ├── ToolChecks.php     suite « Outils CLI »
│   └── NetChecks.php      suite « Reseau »
└── storage/               fichiers de test temporaires (écritures/suppressions)
```

## Ajouter un test

```php
// src/PhpChecks.php
private static function monTest(): Result
{
    return new Result(
        self::CAT,              // catégorie
        'Mon test',             // libellé affiché
        Result::OK,             // OK | WARN | KO | SKIP | INFO
        'valeur constatee',     // affichée en bleu à côté du libellé
        'explication',          // une ligne de contexte
        'how to fix'            // null si rien à corriger
    );
}
```

Puis ajoutez `$out[] = self::monTest();` dans `run()`. Rien d'autre à faire :
le test apparaît dans l'interface web, le CLI et le JSON.

## Utilisation en intégration continue

```bash
php bin/doctor.php --json --fail > report.json
```

Le code de sortie est `1` dès qu'un test est en échec, `0` sinon : à brancher
directement sur un job de pré-déploiement pour bloquer une livraison si la machine
de build est mal configurée.

## Dépannage courant

| Symptôme | Cause probable | Correctif |
|---|---|---|
| Le `.php` s'affiche en texte brut dans le navigateur | `mod_php` non chargé par Apache | Voir ci-dessous |
| `MySQL server has gone away` sur connexion | `mysqld` bloqué ou corrompu | Réinitialiser `C:\xampp\mysql\data` |
| Extensions « manquantes » alors que les DLL existent | lignes commentées dans `php.ini` | Décommenter `extension=...` |
| `python` ouvre le Microsoft Store | alias vide dans `WindowsApps` | Réinstaller depuis python.org |
| Rapport > 10 s | un service ne répond pas (timeouts d'attente) | Regarder le temps par catégorie |
