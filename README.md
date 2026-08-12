# StaffEase Pro

StaffEase Pro est une application web PHP/MySQL pour la gestion du personnel: planning des shifts, presences, documents, messages et espace employe.

## 1. Vue d ensemble

L application repose sur:
- Un front controller unique (`index.php`)
- Un routeur simple (`app/router.php`)
- Des controleurs PHP cote serveur (`backend/controllers/`)
- Des modeles PDO (`backend/models/`)
- Des vues rendues cote serveur (`public/views/`)
- Un JavaScript modulaire vanilla (`assets/js/`)
- Une couche CSS organisee par zone fonctionnelle (`assets/css/`)

Objectifs principaux:
- Planification des shifts par departement
- Gestion des absences (`rest`, `vacation`, `sick`)
- Signature de presence (touchscreen)
- Flux documentaire avec partage et signature
- Messagerie interne entre roles
- Onboarding entreprise avec periode d essai et approbation super_admin
- Emails transactionnels (Brevo) et notifications in-app
- Assistant IA "Giulia" (chat + tutoriel) pour les roles admin/department_manager
- Langue par defaut par entreprise pour les emails/notifications automatiques

## 2. Prerequis

- PHP 8.1+ (8.2 recommande)
- MySQL 8+ (ou MariaDB compatible)
- Extension PDO MySQL active
- Serveur web Apache/Nginx ou serveur integre PHP

Valeurs par defaut en local MAMP (projet actuel):
- Hote DB: `127.0.0.1`
- Port DB: `8889`
- Nom DB: `staff_ease_pro`
- Utilisateur DB: `root`
- Mot de passe DB: `root`

Fichiers de config sensibles, ignores par git (a creer/copier manuellement sur chaque environnement, y compris en hebergement mutualise ou le transfert git ne les copie pas):
- `config/database.php` - connexion MySQL
- `config/mail.php` - emails transactionnels (Brevo), optionnel: sans ce fichier les emails sont simplement desactives
- `config/claude.php` - assistant IA "Giulia" (Groq ou Anthropic), optionnel: sans ce fichier ou avec `enabled => false`, seul le tutoriel statique s affiche

## 3. Installation Locale

### 3.1 Copier le projet

Placez le projet dans une racine web, par exemple:

- `/Applications/MAMP/htdocs/StaffEasePro`

### 3.2 Initialiser la base de donnees

1. Creer la base et les tables via le schema:

```bash
mysql -h 127.0.0.1 -P 8889 -u root -proot < db/schema.sql
```

2. Verifier la configuration de connexion dans `config/database.php`.

Note: `config/env.example` sert de reference, mais la connexion runtime utilise `config/database.php`.

### 3.3 Executer les migrations (optionnel)

Runner disponible:

```bash
php scripts/run_migration.php up 20260601_add_icon_color
php scripts/run_migration.php down 20260601_add_icon_color
```

Les fichiers sont dans `db/migrations/`.

### 3.4 Lancer l application

Depuis la racine projet:

```bash
php -S localhost:8002
```

Puis ouvrir:

- `http://localhost:8002`

## 4. Fonctionnalites Principales

### 4.1 Dashboard (admin, super_admin, department_manager)

- Calendrier des shifts (jour/semaine/quinzaine/mois/annee)
- Affectation et reaffectation avec controle de disponibilite
- Gestion des entreprises, departements, utilisateurs, shifts
- Modal CRUD partagee pour les operations courantes
- Impression et export du planning

### 4.2 Presences

- Signature de presence via canvas tactile
- Contraintes temporelles selon shift
- Statuts (`present`, `late`, `absent`, etc.)
- Correction et annulation depuis l espace de gestion

### 4.3 Documents

- Upload et partage de documents
- Demande de signature documentaire
- Download protege avec controles d autorisation
- Notifications et suivi de statut

### 4.4 Espace Employe (`my-space`)

- Vue des shifts personnels (y compris non-working)
- Signature du shift courant
- Reception de documents et gestion inbox
- Envoi de documents vers roles autorises
- Messages entrants/sortants avec actions en lot

### 4.5 Internationalisation

- Langues gerees dans `config/lang/`: EN, FR, IT
- Textes localises cote PHP et JS
- Langue de session choisie via `?lang=` ou selecteur, stockee en session (`appLocale()`, `backend/helpers.php`)
- Langue par defaut par entreprise (`companies.default_locale`, configurable dans Parametres > Entreprises): les emails et notifications automatiques envoyes a une entreprise (avis d abonnement, creation d utilisateur, changement de shift, decision d approbation) sont rendus dans la langue de l entreprise destinataire via `withLocale()`, independamment de la langue de session de l expediteur (ex: super_admin en italien qui notifie une entreprise francophone)

### 4.6 Onboarding, essai et approbation entreprise

- Inscription self-service (`?route=register`) creant une entreprise en statut `pending`
- Email de demande d autorisation au super_admin avec liens Approuver/Rejeter a usage unique (`?route=company-approval`)
- Periode d essai (`trialPeriodDays()`), avec `trial_ends_at` et notifications de renouvellement/expiration envoyees manuellement par le super_admin (`sendCompanyNotice`, templates `renew_trial` / `renew_payment` / `expiring`)
- `?route=request-authorization` pour renvoyer une demande d autorisation

### 4.7 Emails transactionnels

- Integration Brevo (API REST, `backend/mailer.php`), config dans `config/mail.php` (voir section 2)
- Emails geres: bienvenue utilisateur, entreprise creee, changement de shift, reinitialisation mot de passe, demande/decision d approbation, avis entreprise (abonnement)
- Chaque type peut etre active/desactive individuellement via `notify` dans `config/mail.php`
- `deploy-check.php` (a la racine, a uploader temporairement puis supprimer) verifie la config email/DB/assistant et la connectivite sortante vers Brevo, utile sur hebergement mutualise qui bloque parfois les appels sortants

### 4.8 Assistant IA "Giulia"

- Widget de chat flottant (`assets/js/dashboard/assistant.js`, `assets/css/assistant.css`) visible pour `admin` et `department_manager`
- Sans config IA active: menu tutoriel statique uniquement (aucune erreur, comportement attendu)
- Avec `config/claude.php` (`enabled => true`, fournisseur `groq` ou `anthropic`): chat avec historique (table `ai_assistant_messages`), mode automatique optionnel, et actions proposees (creation de shift, envoi de notification) confirmees explicitement par l utilisateur avant execution
- Page publique de presentation: `?route=giulia`
- Endpoint API: `?route=api-assistant`

## 5. Roles et Permissions (niveau haut)

- `super_admin`: pilotage global multi-company
- `admin`: pilotage complet dans le perimetre entreprise
- `department_manager`: pilotage dans le perimetre departement
- `employee`: acces a l espace personnel (`my-space`)

## 6. Routage Principal

Defini dans `app/router.php`.

Routes utiles:
- `?route=home`
- `?route=commercial`
- `?route=giulia`
- `?route=login` / `?route=logout`
- `?route=register`
- `?route=forgot-password` / `?route=reset-password`
- `?route=company-approval`
- `?route=request-authorization`
- `?route=dashboard`
- `?route=my-space`
- `?route=legal`
- `?route=contacts`
- `?route=creator`

Endpoints API principaux (tous via `backend/controllers/ApiDispatcher.php`, sauf notifications):
- `?route=api-dashboard`
- `?route=api-companies`
- `?route=api-departments`
- `?route=api-users`
- `?route=api-shifts`
- `?route=api-commercial` (videos commerciales, gerees par le super_admin)
- `?route=api-assistant` (assistant IA "Giulia")
- `?route=api-notifications`

## 7. Arborescence

```text
StaffEasePro/
  index.php
  app/
    router.php
    layout/
  assets/
    css/
    js/
    icons/
    images/
  backend/
    bootstrap.php
    db.php
    helpers.php
    controllers/
    models/
  config/
    database.php
    mail.php
    claude.php
    env.example
    lang/
  db/
    schema.sql
    migrations/
  public/
    views/
    legal.php
    contacts.php
    creator.php
  scripts/
    run_migration.php
  deploy-check.php
```

## 8. Architecture Rapide

1. `index.php` lit `route`
2. `app/router.php` resolve controleur ou vue
3. Les controleurs recuperent les donnees via PDO
4. Les vues rendent le HTML cote serveur
5. Le JS client enrichit les modals, le calendrier, le DnD et les appels API

## 9. Deploiement

### 9.1 Shared Hosting (cPanel, InfinityFree, etc.)

1. Uploader les fichiers dans la web root
2. Creer la base MySQL depuis le panel
3. Importer `db/schema.sql`, puis appliquer les migrations de `db/migrations/` (via phpMyAdmin si pas d acces SSH)
4. Mettre a jour `config/database.php` avec les identifiants de prod (fichier ignore par git, a uploader a la main)
5. Uploader `config/mail.php` et `config/claude.php` si les emails transactionnels / l assistant IA doivent etre actifs (egalement ignores par git)
6. Verifier les permissions des dossiers d upload (si utilises)
7. Pointer le domaine/sous-domaine sur la racine du projet
8. Uploader `deploy-check.php`, l ouvrir dans un navigateur pour valider la config, puis le supprimer

Bonnes pratiques:
- Desactiver l affichage d erreurs en production
- Activer HTTPS
- Mettre en place des sauvegardes regulieres

### 9.2 VPS (Apache/Nginx)

1. Installer PHP + MySQL
2. Copier le projet sur le serveur
3. Configurer le virtual host vers la racine projet
4. Importer le schema et configurer `config/database.php`
5. Ajuster les permissions du user web sur les dossiers runtime/upload
6. Redemarrer le serveur web et tester les routes critiques

### 9.3 Checklist Production

- [ ] Credentials DB non par defaut
- [ ] HTTPS actif
- [ ] Backups automatiques
- [ ] Logs erreurs actifs
- [ ] Tests des roles (super_admin/admin/manager/employee)
- [ ] Validation des flux critiques (presences, documents, messages, calendrier)

## 10. Commandes Utiles

Verifier la syntaxe PHP:

```bash
php -l index.php
```

Demarrer le serveur local:

```bash
php -S localhost:8002
```

Executer une migration:

```bash
php scripts/run_migration.php up NOM_MIGRATION
```

## 11. Troubleshooting Rapide

- Erreur de connexion DB:
  - Verifier host/port/user/password dans `config/database.php`
  - Verifier que MySQL est demarre

- Page blanche ou route invalide:
  - Verifier le mapping dans `app/router.php`
  - Lancer `php -l` sur les fichiers modifies

- Probleme document/download:
  - Verifier les permissions utilisateur
  - Verifier la presence des fichiers et les droits dossiers

- Apres un deploiement (hebergement mutualise notamment):
  - `config/database.php`, `config/mail.php`, `config/claude.php` sont ignores par git: ils doivent etre uploades manuellement (FTP) sur chaque environnement, ils ne voyagent jamais avec le code
  - Uploader temporairement `deploy-check.php` a la racine web, l ouvrir dans un navigateur pour verifier DB/permissions dossiers/email/assistant IA en un coup d oeil, puis le supprimer du serveur (il expose des details de configuration)
  - Assistant IA qui repond toujours en erreur: verifier que les migrations `ai_assistant_messages` et `requests.sender_id`/`requires_response` ont bien ete appliquees sur la base de production (pas seulement en local)
  - Textes affiches comme des cles brutes (ex: `assistant.title`) au lieu du texte traduit: fichiers `config/lang/*.php` obsoletes sur le serveur, re-uploader

## 12. Quick Start en 5 Minutes (MAMP)

1. Import DB:

```bash
mysql -h 127.0.0.1 -P 8889 -u root -proot < db/schema.sql
```

2. Verifier `config/database.php` (host `127.0.0.1`, port `8889`, db `staff_ease_pro`).

3. Lancer l app:

```bash
php -S localhost:8002
```

4. Ouvrir `http://localhost:8002?route=login`.

5. Creer un premier admin (section suivante), puis se connecter.

## 13. Seed Premier Compte Admin

Le projet ne force pas un compte demo par defaut dans ce README. Vous pouvez creer un premier admin manuellement.

### 13.1 Generer un hash de mot de passe

```bash
php -r "echo password_hash('ChangeMeNow123!', PASSWORD_DEFAULT), PHP_EOL;"
```

Copiez la valeur retournee.

### 13.2 Inserer un utilisateur admin

```sql
INSERT INTO users (
  department_id,
  first_name,
  last_name,
  email,
  phone,
  password,
  role,
  status
) VALUES (
  NULL,
  'Super',
  'Admin',
  'admin@staffease.local',
  NULL,
  'COLLER_ICI_LE_HASH',
  'super_admin',
  'active'
);
```

Puis connectez-vous avec:
- Email: `admin@staffease.local`
- Mot de passe: celui choisi avant hash

## 14. Runbook Deploiement (Post-release)

### 14.1 Avant mise en ligne

1. Sauvegarder DB et fichiers
2. Verifier la config `config/database.php`
3. Verifier les permissions dossiers upload/runtime
4. Lancer un controle syntaxe PHP rapide

### 14.2 Mise en ligne

1. Deployer les fichiers applicatifs
2. Appliquer schema/migrations necessaires
3. Vider cache/opcache si present
4. Verifier accessibilite HTTPS

### 14.3 Verification fonctionnelle

1. Login super_admin
2. Ouverture dashboard
3. Test creation/modification utilisateur
4. Test affectation shift sur calendrier
5. Test signature presence (role employee)
6. Test upload + partage document
7. Test download document et notifications
8. Test assistant IA "Giulia" (envoi d un message, si `config/claude.php` est actif)
9. Test envoi d un email (creation utilisateur ou avis entreprise, si `config/mail.php` est actif)

### 14.4 Rollback (si incident)

1. Restaurer code precedent
2. Restaurer backup DB si migration destructrice
3. Redemarrer services web/PHP
4. Verifier routes critiques

