# customscript-signuptoken
A moodle 3.11.5+ Customscript version of /login/signup.php for use with local-signuptoken

## Installation

You need to modify your moodle's `config.php` file to install this. Place a `customscripts` folder (name is not important) somewhere that the web server can execute - inside the Moodle folder is usually ok. Then modify config.php to include a reference to the location of the customfolder script

```php
$CFG->customscripts = __DIR__ . '/customscripts';
```

The folder naming inside customscripts is important - it must match the folder names and php file names of the scripts it will be overriding.

## Documentation

For more information on customscripts see https://docs.moodle.org/dev/customscripts

## Licence

MIT.