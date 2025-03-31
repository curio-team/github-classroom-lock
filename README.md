# GitHub Classroom Lock

Locks a GitHub Classroom by removing all students from their team. It keeps track of the students that were removed to allow restoring them later.

This helps us restrict access when students leave our school.

## Getting Started

1. Clone this repository
2. Run `composer install`
3. Copy `.env.example` to `.env` and fill in the values (see [#Access Tokens](#access-tokens) for more info)
4. Run `php artisan key:generate`
5. `npm run dev` to run vite and compile the frontend (watching for changes)
6. `php artisan serve` to run the server
7. Open `http://localhost:8000` in your browser
8. Run `php artisan migrate` to create the database

## Access Tokens

### GitHub access token

1. [Get a Fine-grained access token from GitHub here](https://github.com/settings/personal-access-tokens/new).
2. Select the `curio-summatief` organization
3. Select `All repositories` under 'Repository access'
4. Give it the following permissions:
    * *(Optional) Repository permissions:*
        * *Contents: read*
    * Organization permissions:
        * Members: read and write
        * Projects: admin

### OpenAI access token

1. [Get an OpenAI API key here](https://platform.openai.com/api-keys).
2. Copy the key and paste it in the `.env` file as `OPENAI_API_KEY`.
3. Make sure you've prepaid some money to your OpenAI account.

## Notes:

### cURL error 60: SSL certificate expired

To test locally it can be useful to add `SD_SSL_VERIFYPEER=no` to your .env file. This disables SSL verification, which is not recommended for production.

### Disable output buffering in PHP

In order for the frontend to receive the chat output immediately, you need to disable output buffering in PHP. You can do this by setting `output_buffering = off` in your `php.ini` file.

> [!TIP]
> I have no idea why, but if you can't get output buffering to stop, I found that in `/etc/apache2/conf-available/php8.1-fpm.conf`:
> ```apache
> <FilesMatch ".+\.ph(?:ar|p|tml)$">
> #    SetHandler "proxy:unix:/run/php/php8.1-fpm.sock|fcgi://localhost"
> </FilesMatch>
> ```
> You can comment the SetHandler line to simplify the setup and ensure no other settings are interfering.
> Please open an issue if you can tell me why this works.
