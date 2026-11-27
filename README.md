# GitHub Exam Onboarding

Sets up exams on GitHub for Curio Software Development. Every exam group gets its own team, private
repository and Project in the `curio-summatief` organization. Students join with one invite link, and
examinators can lock and unlock the groups during the exam.

## How an exam works

On the exam page, the examinator goes through five steps:

1. **Studenten:** upload the students taking the exam, with their exam group. This is a CSV with `github_username`
   and `group`; an Excel export works too. Unknown usernames, duplicates and empty groups are reported before
   anything is saved. A corrected list can simply be uploaded again. Small changes don't need a new list; see
   [Changing groups during the exam](#changing-groups-during-the-exam).
2. **Klaarzetten:** one click creates the team, private repository (optionally from a template) and Project
   (optionally copied from a template) for each group. Running it again is safe: what already exists is reused.
   Groups start **locked**: their team has no access to the repository and Project until you unlock it in step 4. So
   you can prepare everything and let students join weeks in advance, and open it all on the day of the exam. A group
   added while the exam is open (at least one group unlocked) starts open, so moving a student mid-exam doesn't lock
   them out.
3. **Uitnodigen:** share the invite link. Students sign in with GitHub and land in their own group. Only students
   on the list can join, so a forwarded link is useless to others. The page shows who is in and who isn't yet.
4. **Tijdens het examen:** unlock all groups when the exam starts. Lock a group, or all groups, at the end of the
   day, and unlock at the start of the next.
   Locking removes the team's access to its repository and Project. Students stay in their team. GitHub does take
   them off the repository's issues and pull requests when they lose access, so the app stores who was assigned to
   what before locking and assigns them again after unlocking (see [Issue assignments and locking](#issue-assignments-and-locking)).
5. **Afsluiten:** after the exam, students are removed from their teams and from `curio-summatief`, and the
   repositories are archived (read-only). You see exactly what will happen and confirm by typing the exam name.
   Organization owners are never removed. **Backup maken** puts a full `git clone` of every repository (all files,
   commits and branches) and an overview of the groups and students into one zip to download. Issues, pull requests
   and Projects aren't included. Once everything is graded, **Opruimen na het beoordelen** permanently
   deletes the exam's Projects, repositories and teams from GitHub, with the same preview and confirmation.
   Organization owners can restore a deleted repository for 90 days (**Settings → Deleted repositories**).

Every change on GitHub is recorded in the **Auditlog**.

### Changing groups during the exam

Students can be added, moved and removed one at a time, in step 1 (**Eén student toevoegen**, and **Wijzigen** next
to each student) and in step 3 (**Student toevoegen** under the list, and **Wijzigen** in each row). Typing a group
name that doesn't exist yet creates that group.

* **A student can't sign in to their GitHub account:** add the account they can use to the same group, then remove the
  old one. The new account joins with the existing invite link.
* **Moving** a student who has already joined updates the teams on GitHub right away. If the new group isn't on GitHub
  yet, prepare it in step 2 (**Klaarzetten**); the student is moved to its team as soon as it's ready, and keeps
  working in the old group until then.
* **Removing** a student takes them out of their team and withdraws their invitation or membership of the
  organization. Students who were already members before the exam, organization owners, and students who are in
  another exam that is still running stay in the organization.
* A group that ends up empty is removed, unless it already exists on GitHub.

### Issue assignments and locking

When the team loses access to its repository, GitHub unassigns its students from the repository's issues and pull
requests. (Draft issues in the Project keep their assignees: they don't belong to the repository.) So:

* **Locking** first stores which student was assigned to which issue or pull request (open and closed), then removes
  the access. A locked group shows how many assignments were stored. If storing fails, the group is still locked
  (closing the exam matters more) and the group shows a warning; those assignments then have to be set again by hand.
* **Unlocking** gives the access back and then assigns the students again. Students who left the group in the meantime
  are skipped, as are issues that no longer exist. Nobody is taken off an issue. GitHub unassigns in the background
  and silently ignores assignees it can't add, so a minute later the app checks again, and once more if needed. Until
  then the group shows *Toewijzingen van issues terugzetten…*. If it still fails, the group shows a warning and the
  next unlock tries again.

Each restored assignment gets the student a GitHub notification ("assigned you"), and the issue's timeline shows the
unassignment and reassignment by the app.

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
5. Run `php artisan queue:work`: preparing groups, locking, closing an exam and making backups run as queued jobs.
   Backups need `git` on the server and PHP's `zip` extension, and are stored in `storage/app/backups`.
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
   | Repository | Contents | Read & write | Creating repositories from a template, cloning them for backups |
   | Repository | Issues | Read & write | Storing and restoring issue assignments when locking and unlocking |
   | Repository | Pull requests | Read & write | Storing and restoring pull request assignments when locking and unlocking |
   | Repository | Metadata | Read | (required) |
   | Organization | Members | Read & write | Teams (creating and deleting), invitations, memberships |
   | Organization | Administration | Read & write | Base permission, organization-wide Projects switch |
   | Organization | Projects | Read & write | Creating, linking, locking and deleting Projects |

   Already have the app? Add **Issues** and **Pull requests** (Read & write), then accept the new permissions under
   **curio-summatief → Settings → GitHub Apps → Configure**. Until then, locking still works but shows a warning that
   the assignments couldn't be stored.

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
* A student left out of an uploaded list keeps their GitHub access. Use **Wijzigen → Verwijderen** instead to also
  revoke it.
* If GitHub won't let the app accept the invitation automatically, the student gets a button to accept it on
  github.com (one extra click).
* Using a classic `GITHUB_TOKEN` instead of the App? Deleting repositories also needs its `delete_repo` scope.

## Development

In the code, an exam is a `Cohort` (with `Group`s and `Student`s). The GitHub calls live in `app/Services`.

`php artisan test` runs against SQLite and a simulated GitHub API
([tests/Support/FakeGitHub.php](tests/Support/FakeGitHub.php)). Real requests to GitHub are blocked.
