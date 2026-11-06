# Modèle de menaces STRIDE et checklist OWASP

**Objectif :** analyser ce qu'un attaquant pourrait faire sur chaque composant, et vérifier quelles protections existent dans le MVP.

**Ticket Jira :** [SCRUM-24](https://wissalshalaoui.atlassian.net/browse/SCRUM-24)

## Rappel STRIDE

| Lettre | Menace | Ce qu'elle viole |
|---|---|---|
| **S** | Spoofing : se faire passer pour quelqu'un | Authentification |
| **T** | Tampering : modifier des données ou des requêtes | Intégrité |
| **R** | Repudiation : nier avoir fait une action | Traçabilité |
| **I** | Information disclosure : accéder à des données interdites | Confidentialité |
| **D** | Denial of service : rendre le service indisponible | Disponibilité |
| **E** | Elevation of privilege : obtenir plus de droits | Autorisation |

## Composants analysés

```mermaid
flowchart LR
    U([Navigateur]) -- HTTP --> N[nginx]
    N --> APP[App Laravel]
    APP --> DB[(MySQL)]
    APP --> R[(Redis)]
    APP -- "JSON-RPC + token" --> ZW[API Zabbix]
    APP -- SMTP --> M[Mailpit / serveur mail]
    ZW --> ZS[Zabbix server] -- HTTP --> T([APIs surveillées])
```

Frontières de confiance : le navigateur (non fiable) → l'application ; l'application → Zabbix (secret partagé : le token API) ; Zabbix → les URL surveillées (saisies par les utilisateurs).

## Tableau STRIDE

| Composant | Menace | Exemple d'attaque | Contre-mesure | Statut |
|---|---|---|---|---|
| Authentification | **S** | Deviner un mot de passe par force brute | 5 essais / min par email + IP ; mots de passe hachés bcrypt (coût 12), 8 caractères minimum | ✅ |
| Authentification | **S** | Créer un compte avec l'email de quelqu'un d'autre | Vérification d'email obligatoire avant tout accès | ✅ |
| Authentification | **S** | Voler une session | Cookies `HttpOnly` et `SameSite=Lax`, session régénérée à la connexion ; en production : HTTPS obligatoire, HSTS, cookies `Secure` (SCRUM-61) | ✅ |
| Réinitialisation du mot de passe | **S / T** | Réutiliser ou forger un lien | Jeton aléatoire valable 60 min, usage unique ; liens de vérification signés | ✅ |
| Formulaires | **T** | Faire agir un utilisateur connecté à son insu (CSRF) | Jeton CSRF sur tous les formulaires `POST/PUT/DELETE` | ✅ |
| Formulaires | **T** | Injection SQL | Eloquent / requêtes préparées ; recherche `LIKE` échappée | ✅ |
| Pages | **T / I** | Injection de script (XSS) via un nom de target | Échappement automatique Blade (`{{ }}`) | ✅ |
| Targets et alertes | **I** | Voir les targets d'une autre team en changeant l'id dans l'URL | Policies `view/update/delete`, scope `visibleTo`, filtres limités aux targets visibles | ✅ tests Feature |
| Targets et alertes | **E** | Un Viewer qui modifie, supprime ou acquitte | Permissions nommées par rôle (`target.update`, `alert.acknowledge`…), matrice unique dans `TeamRole` ; 403 sinon (SCRUM-58) | ✅ tests Feature (matrice complète) |
| Création du compte par invitation | **S / E / T** | Réutiliser ou modifier un lien ; choisir une autre adresse que celle invitée ; inviter sans en avoir le droit | Lien signé et expirant, invitation supprimée à l'usage ; email pris dans l'invitation, jamais dans le formulaire ; POST réservé aux invités non connectés, limité à 6/min ; gate `team.invite` (Admin de la team ou Super Admin) ; `user.create` et `member.join` dans l'audit (SCRUM-83) | ✅ tests Feature |
| Inscription | **S / E / I** | Un inconnu crée un compte et voit des données ; une team créée à chaque inscription ; un domaine imité (`company.com.evil.io`) | `REGISTRATION_MODE=invite` par défaut (404), valeur inconnue = invite ; mode domain : domaine exact après le `@`, email vérifié avant tout accès ; compte créé sans team, aucune page de team sans appartenance (`EnsureUserHasTeam`) ; Super Admin prévenus après vérification ; `user.create` dans l'audit (SCRUM-82) | ✅ tests Feature |
| Super Admin de la plateforme | **E** | Devenir Super Admin par le web (formulaire, champ injecté) ; plateforme sans administrateur | Rôle créé seulement en ligne de commande (`platform:create-admin`, mot de passe saisi masqué) ; colonne hors `$fillable` ; jamais de retrait du dernier Super Admin (verrou), ni suppression de son compte ; `platform.admin_grant` / `platform.admin_revoke` dans l'audit (SCRUM-81) | ✅ tests Feature |
| Règles de notification | **E / R** | Un Viewer qui coupe les emails d'alerte de sa team, ou une modification niée | Permission `notification.manage` (Admin, Manager), 403 sinon ; chaque modification dans `audit_logs` (`notification_rule.update`, ancien → nouveau) (SCRUM-55) | ✅ tests Feature |
| Historique des notifications | **I** | Lire les notifications d'une autre team, les emails des membres ou le détail du serveur SMTP | Permission `alert.view` sur la team, filtres `target` / `alert` validés sur les targets de la team ; emails réservés à `user.view`, erreurs SMTP à `configuration.manage` (SCRUM-56) | ✅ tests Feature |
| API interne (graphiques) | **I** | Lire les mesures d'une target d'une autre team | Même authentification et policy `view` que les pages | ✅ |
| Appels Zabbix | **I** | Fuite du token API | Token dans `.env` (jamais commité), jamais écrit dans les logs ; token dédié à l'application | ✅ |
| Appels Zabbix | **T** | Saisir une URL interne pour sonder le réseau (SSRF via Zabbix) | URL limitée à `http`/`https` ; règle `PublicUrl` : refus des adresses locales, privées, link-local et réservées (IPv4 et IPv6), contrôlé sur les IP résolues ; liste blanche pour la démo (SCRUM-57) | ✅ |
| Appels Zabbix | **D** | Zabbix lent ou en panne bloque l'appli | Timeout 15 s, nouvelles tentatives sur les lectures seulement ; pages servies depuis la base ; bandeau générique | ✅ |
| Actions sensibles | **R** | Nier avoir supprimé une target ou acquitté une alerte | `audit_logs` : qui, quoi, quand, IP, anciennes et nouvelles valeurs | ✅ |
| Erreurs | **I** | Lire une trace technique (chemins, requêtes) | Messages génériques (§47) ; `APP_DEBUG=false` en production, contrôlé par `php artisan security:check` (SCRUM-61) | ✅ |
| Emails | **S** | Faux emails de réinitialisation (phishing) | Expéditeur unique `noreply@…` ; SPF/DKIM à configurer en production | ⏳ |
| Webhooks (V2) | **S / T** | Appeler un webhook entrant sans y être autorisé | Signature HMAC des webhooks prévue avec la fonctionnalité | ⏳ V2 |
| Grafana | **S** | Se faire passer pour un autre compte Grafana en envoyant soi-même l'en-tête `X-WEBAUTH-USER` | nginx remplace toujours cet en-tête par le login donné par Laravel ; Grafana ne l'accepte que de l'adresse fixe de nginx sur un réseau interne (`GF_AUTH_PROXY_WHITELIST`) ; comptes créés par la plateforme seulement (SCRUM-48) | ✅ testé : en-tête refusé sur le port direct (`auth-proxy.invalid-ip`) |
| Grafana | **I** | Voir les hosts d'une autre team dans Grafana | Une organisation Grafana par team, dont la datasource se connecte avec un utilisateur Zabbix en lecture seule sur le seul host group de la team ; compte Viewer des seules organisations de ses teams, retiré de l'organisation principale ; retrait de team répercuté tout de suite, synchro horaire (SCRUM-48) | ✅ testé : 0 donnée, 403 sur les autres organisations |
| Grafana | **E** | Un utilisateur qui ajoute une datasource vers le réseau interne | Rôle Viewer partout, pas de création d'organisation ; mot de passe admin de Grafana seulement dans `.env`, utilisé côté serveur (SCRUM-48) | ✅ testé : 403 |
| Base de données | **I / D** | Accès direct à MySQL ou phpMyAdmin | Ports réservés à la machine locale (phpMyAdmin sur `127.0.0.1`) ; non exposés en production : `docker-compose.prod.yml` retire le port MySQL et arrête phpMyAdmin (SCRUM-61) | ✅ (MySQL publié sur `127.0.0.1:3307` en dev seulement) |

## Checklist OWASP Top 10 (2021) appliquée au projet

| Catégorie | Ce qui est en place | Reste à faire |
|---|---|---|
| **A01 Contrôle d'accès défaillant** | Policies Laravel sur chaque action, permissions nommées par rôle (RBAC, SCRUM-58), cloisonnement par team, filtres limités aux données visibles, tests de toute la matrice rôle × action ; gestion des membres et invitations (SCRUM-59) ; Grafana cloisonné par team (organisation + utilisateur Zabbix en lecture seule, SCRUM-48) | — |
| **A02 Défaillances cryptographiques** | bcrypt pour les mots de passe, URLs signées, `APP_KEY` secrète ; en production : HTTPS (TLS 1.2 / 1.3), HSTS, cookies `Secure` et session chiffrée (SCRUM-61) | — |
| **A03 Injection** | Requêtes préparées, `LIKE` échappé, validation serveur (FormRequest), échappement Blade | — |
| **A04 Conception non sécurisée** | Analyse STRIDE, création « tout ou rien », aucune page dépendante de Zabbix | Revoir à chaque nouvelle fonctionnalité V1 |
| **A05 Mauvaise configuration de sécurité** | `.env` hors du dépôt, ports de dev limités à la machine locale ; CSP sans script inline, `X-Frame-Options`, `nosniff`, `Referrer-Policy` ; `.env.production.example` (`APP_DEBUG=false`) vérifié par `security:check` ; MySQL non publié en production (SCRUM-61) | Styles inline encore autorisés par la CSP (`'unsafe-inline'` sur `style-src`) |
| **A06 Composants vulnérables** | `composer audit` et `npm audit --audit-level=high` dans la CI ; images Zabbix épinglées sur une version précise ; mises à jour mensuelles documentées (SCRUM-64) | Images encore épinglées sur la version majeure seulement (`phpmyadmin:5`, `redis:7`) : outils de dev |
| **A07 Identification et authentification** | Limitation des tentatives, vérification d'email, réinitialisation sécurisée | 2FA (optionnel, §28) |
| **A08 Intégrité des données et du logiciel** | Une PR par ticket, CI verte avant chaque fusion, synchro idempotente | Protection de branche sur `main` (non disponible sur un dépôt privé gratuit) |
| **A09 Journalisation et surveillance** | Audit des actions sensibles, consultable par l'Admin de la team et purgé après 1 an (SCRUM-60), logs Zabbix dédiés, log CRITICAL si la synchro échoue | Alerte quand la synchro échoue plusieurs fois |
| **A10 SSRF** | URL de target limitée à `http`/`https` ; adresses internes refusées après résolution DNS (SCRUM-57) | Limite connue : un nom dont l'adresse change après la validation (DNS rebinding) n'est pas recontrôlé |

## Conclusion

Le MVP couvre les menaces principales : authentification, cloisonnement entre teams, injections, traçabilité et indisponibilité de Zabbix. La **configuration de production** (HTTPS, en-têtes, `APP_DEBUG=false`, logs) est prête depuis SCRUM-61 ; il reste à configurer SPF/DKIM pour les emails. Le **SSRF**, relevé par cette analyse, est corrigé depuis SCRUM-57 : une target ne peut plus viser une adresse du réseau interne.
