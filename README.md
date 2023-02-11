# GitHub Classroom Lock

Locks a GitHub Classroom by removing all students from their team. It keeps track of the students that were removed to allow restoring them later.

This helps us restrict access when students leave our school.

## Getting Started

1. Clone this repository
2. Run `composer install`
3. Copy `.env.example` to `.env` and fill in the values (see [#Access Token](#access-token) for more info)
4. Run `php artisan key:generate`
5. `npm run dev` to run vite and compile the frontend (watching for changes)
6. `php artisan serve` to run the server
7. Open `http://localhost:8000` in your browser
8. Run `php artisan migrate` to create the database

## Access Token

1. [Get a Fine-grained access token from GitHub here](https://github.com/settings/personal-access-tokens/new).
2. Select the `curio-studenten` organization
3. Give it the following permissions:
    * Organization permissions:
        * Members: read and write
    * *(Optional) Repository permissions:*
        * *Contents: read*

## Notes:

### cURL error 60: SSL certificate expired
To test locally it can be useful to change line `28` in `/vendor/studiokaa/amoclient/src/AmoclientController.php` to `$http = new \GuzzleHttp\Client(['curl' => [CURLOPT_SSL_VERIFYPEER => false]]);`. On production you should just enable HTTPS.
