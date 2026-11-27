# GitHub Exam Onboarding

Sets up exams on GitHub for Curio Software Development. Every exam group gets its own team, private
repository and Project in the `curio-summatief` organization. Students join with one invite link, and
examinators can lock and unlock the groups during the exam.

## How an exam works

On the exam page, the examinator goes through five steps:

1. **Studenten:** upload the students taking the exam, with their exam group. This is a CSV with `github_username`
   and `group`; an Excel export works too. Unknown usernames, duplicates and empty groups are reported before
   anything is saved. A corrected list can simply be uploaded again.
2. **Klaarzetten:** one click creates the team, private repository (optionally from a template) and Project
   (optionally copied from a template) for each group. Running it again is safe: what already exists is reused.
3. **Uitnodigen:** share the invite link. Students sign in with GitHub and land in their own group. Only students
   on the list can join, so a forwarded link is useless to others. The page shows who is in and who isn't yet.
4. **Tijdens het examen:** lock a group, or all groups, at the end of the day, and unlock at the start of the next.
   Locking removes the team's access to its repository and Project. Students stay in their team, so assigned
   issues and history are kept.
5. **Afsluiten:** after the exam, students are removed from their teams and from `curio-summatief`, and the
   repositories are archived (read-only). You see exactly what will happen and confirm by typing the exam name.
   Organization owners are never removed. Once everything is graded, **Opruimen na het beoordelen** permanently
   deletes the exam's Projects, repositories and teams from GitHub, with the same preview and confirmation.
   Organization owners can restore a deleted repository for 90 days (**Settings → Deleted repositories**).

Every change on GitHub is recorded in the **Auditlog**.

### Projects and locking

A Project's *base role* (what every org member may do) can't be read or changed through the GitHub API. If a
Project gives all org members access, students can still open it while their group is locked. Check this once
in a Project under **Settings → Manage access**. If it's set, use **Projects voor de hele organisatie uitzetten**
in step 4. Note that this switch affects every exam running at the same time.

## Getting started

1. `composer install` and `npm install`
2. Copy `.env.example` to `.env`, fill it in (see [GitHub App](#github-app)), and run `php artisan key:generate`
3. `php artisan migrate`
4. `npm run build` (or `npm run dev`), then `php artisan serve`
5. Run `php artisan queue:work`: preparing groups, locking and closing an exam run as queued jobs
6. Check the setup with `php artisan github:check`. It only reads, so it's safe against production.

Examinators sign in with the SD client (login.curio.codes). Students sign in with GitHub.

## GitHub App

One GitHub App does both jobs: it makes all changes in the organization, and it handles the students'
"Sign in with GitHub".

1. Go to **curio-summatief → Settings → Developer settings → GitHub Apps → New GitHub App**.
2. Fill in:
   * **Homepage URL:** the app's URL
   * **Redirect URI** (under *Identifying and authorizing users*; older screens call it *Callback URL*):
     `{APP_URL}/join/github/callback`, matching `APP_URL` exactly. Use **Add redirect URI** to add one per
     environment, e.g. `http://127.0.0.1:8000/join/github/callback` and
     `https://github.curio.codes/join/github/callback`. A GitHub App can have up to 10.
   * **Allow wildcard matching:** off. It would let tokens be sent to any subdomain or deeper path.
   * **Request user authorization (OAuth) during installation:** off
   * **Enable Device Flow:** off
   * **Webhook → Active:** off
   * **Where can this GitHub App be installed?** Only on this account
3. **Permissions:**

   | Type | Permission | Access | Used for |
   | --- | --- | --- | --- |
   | Repository | Administration | Read & write | Creating, locking, archiving and deleting repositories |
   | Repository | Contents | Read & write | Creating repositories from a template |
   | Repository | Metadata | Read | (required) |
   | Organization | Members | Read & write | Teams (creating and deleting), invitations, memberships |
   | Organization | Administration | Read & write | Base permission, organization-wide Projects switch |
   | Organization | Projects | Read & write | Creating, linking, locking and deleting Projects |

4. Create the app, then on its settings page:
   * Note the **App ID** and **Client ID**
   * **Generate a new client secret**
   * **Generate a private key** and store the `.pem` outside git (`*.pem` is ignored)
5. **Install App** → `curio-summatief` → **All repositories**. The installation ID is the number at the end of the
   URL you land on (`…/settings/installations/<id>`).
6. Fill in `.env`:

   ```dotenv
   GITHUB_ORGANIZATION=curio-summatief
   GITHUB_APP_ID=
   GITHUB_APP_INSTALLATION_ID=
   GITHUB_APP_PRIVATE_KEY_PATH=github-bot.pem   # absolute, or relative to the project root
   GITHUB_OAUTH_CLIENT_ID=                      # the app's Client ID (starts with Iv)
   GITHUB_OAUTH_CLIENT_SECRET=
   ```

7. Run `php artisan github:check`.

Also set the organization's **base repository permission** to **No permission**, so students only see their own
group's repository. The exam page warns you if it isn't. Staff are owners, so they keep access to everything.

## Good to know

* GitHub allows 500 invitations per 24 hours, and an unaccepted invitation expires after 7 days. A student whose
  invitation expired can simply open the link again.
* A student removed from the list keeps their GitHub access. Close the exam (step 5) for them first if needed.
* If GitHub won't let the app accept the invitation automatically, the student gets a button to accept it on
  github.com (one extra click).
* Using a classic `GITHUB_TOKEN` instead of the App? Deleting repositories also needs its `delete_repo` scope.

## Development

In the code, an exam is a `Cohort` (with `Group`s and `Student`s). The GitHub calls live in `app/Services`.

`php artisan test` runs against SQLite and a simulated GitHub API
([tests/Support/FakeGitHub.php](tests/Support/FakeGitHub.php)). Real requests to GitHub are blocked.
