{
    "name": "glueful/{{EXTENSION_NAME|lower}}",
    "description": "{{EXTENSION_DESCRIPTION}}",
    "type": "glueful-extension",
    "license": "MIT",
    "authors": [
        {
            "name": "{{AUTHOR_NAME}}",
            "email": "{{AUTHOR_EMAIL}}"
        }
    ],
    "require": {
        "php": ">=8.2.0"
    },
    "autoload": {
        "psr-4": {
            "Glueful\\Extensions\\{{EXTENSION_NAME}}\\": "src/"
        }
    },
    "extra": {
        "glueful": {
            "class": "{{EXTENSION_NAME}}",
            "providers": []
        }
    }
}