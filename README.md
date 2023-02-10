# GitHub Classroom Lock

Locks a GitHub Classroom by removing all students from their team. It keeps track of the students that were removed to allow restoring them later.

This helps us restrict access when students leave our school.

## Notes:

### cURL error 60: SSL certificate expired
To test locally it can be useful to change line `28` in `/vendor/studiokaa/amoclient/src/AmoclientController.php` to `$http = new \GuzzleHttp\Client(['curl' => [CURLOPT_SSL_VERIFYPEER => false]]);`. On production you should just enable HTTPS.
