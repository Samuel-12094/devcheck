# DevEnv Doctor

**Diagnostic de votre environnement de développement en 10 secondes.**

XAMPP ne marche plus ? MySQL refuse de se connecter ? PHP ne charge pas ?
DevEnv Doctor vérifie tout automatiquement et vous dit exactement quoi corriger.

## Ce qui est testé

| Catégorie | Tests |
|---|---|
| **Serveur & PHP** | Version, extensions, php.ini, sessions, fuseau horaire |
| **Base de données** | Connexion mysqli, CRUD complet, transactions InnoDB |
| **Fichiers & droits** | Écriture, lecture, suppression, permissions |
| **Outils CLI** | Node, npm, Git, Composer, PHP CLI, VS Code |
| **Réseau** | Ports, DNS, HTTPS sortant |

## Démarrage rapide

### Dans un terminal

```bash
C:\xampp\htdocs\devcheck\bin\doctor.cmd
```

### Dans le navigateur

```bash
C:\xampp\htdocs\devcheck\bin\serve.cmd
```

Puis http://localhost:8080

## Pourquoi DevEnv Doctor ?

- **36 tests** — Couverture complète de votre stack
- **0 dépendance** — Fonctionne sans Composer
- **Correctifs exacts** — Chaque échec dit quoi corriger
- **Interface web** — Rapport visuel, filtrable, exportable
- **CLI** — Pour l'intégration continue
- **Open source** — Licence MIT

## Cas d'usage

| Problème | Solution |
|---|---|
| XAMPP ne démarre pas | Vérifiez les ports et les services |
| MySQL ne se connecte pas | Vérifiez les identifiants et le port |
| Extension PHP manquante | Décommentez dans php.ini |
| "Ça marche pas" | Obtenez un rapport détaillé en 10 secondes |

## Options CLI

| Option | Effet |
|---|---|
| `--only=db` | Limite à une catégorie : `web`, `db`, `fs`, `tools`, `net` |
| `--json` | Sortie JSON (pour script ou CI) |
| `--fail` | Code de sortie `1` s'il y a au moins un échec |
| `--no-color` | Désactive les couleurs ANSI |

## Structure du projet

```
devcheck/
├── index.php              interface web (autonome, sans framework)
├── config.php             configuration
├── composer.json          métadonnées + scripts
├── bin/
│   ├── doctor.php         moteur du rapport CLI
│   ├── doctor.cmd         lanceur Windows
│   └── serve.cmd          serveur web local
├── src/
│   ├── autoload.php       autoloader PSR-4 minimal
│   ├── Doctor.php         orchestrateur des suites
│   ├── Report.php         agrégation, score, sérialisation
│   ├── Result.php         objet résultat (statut, valeur, correctif)
│   ├── Support.php        PATH, exécutables, ports, formats
│   ├── PhpChecks.php      suite « Serveur & PHP »
│   ├── DbChecks.php       suite « Base de données »
│   ├── FsChecks.php       suite « Fichiers & droits »
│   ├── ToolChecks.php     suite « Outils CLI »
│   └── NetChecks.php      suite « Réseau »
└── storage/               fichiers de test temporaires
```

## Licence

MIT — Utilisez-le, modifiez-le, vendez-le.

## Liens

- **Page de vente** : https://samuel-12094.github.io/devcheck/
- **Gumroad** : https://houssou3.gumroad.com/l/lowbft
