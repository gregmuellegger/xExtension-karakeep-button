# FreshRSS Karakeep Button
A [FreshRSS](https://freshrss.org/) extension which adds a [Karakeep](https://karakeep.app/) sharing integration.

With this extension you can simply press the Karakeep Button next to an article or a custom keyboard shortcut to share it with Karakeep. Everything else happens in the background while you can continue reading articles without any further interruptions.

## Download and setup
1. Download the [latest release](https://github.com/veverkap/xExtension-karakeep-button/releases)
2. Extract and upload it to the `./extensions` folder of your FreshRSS installation
3. Go to your Karakeep instance User Settings -> API Keys
4. Create a new API key with the [permissions listed below](#required-api-key-permissions)
5. Enter your Karakeep instance url in the Karakeep Button extension settings
6. Enter your API key in the Karakeep Button extension settings
7. Press "Connect to Karakeep"
8. *Optional Set a custom keyboard shortcut*

## Required API key permissions
Karakeep 0.32.0 and later lets you restrict an API key to individual scopes. The extension uses two endpoints, so the key needs:

| Scope | Permission | Used for |
| --- | --- | --- |
| User account | Read | `GET /api/v1/users/me`, to verify the key and read your username when you press "Connect to Karakeep" |
| Bookmarks | Read/write | `POST /api/v1/bookmarks`, to save an article |

No other scope is needed, `Assets` included, so everything else can stay at "No access".

Missing the `User account` scope is the common cause of a 403 when connecting: the key can save bookmarks just fine, but the connect step never gets past its check against `/users/me`.

Keys created before 0.32.0, or created with full access, already cover both scopes.

## Karakeep API Error codes
If you get errors while trying to connect to Karakeep, please check the [Karakeep API Documentation](https://docs.karakeep.app/api/karakeep-api).

## Contributing

### Translations
If you'd like to translate the extension to another language please file a pull request. I'd be happy to merge it!

### Development
For local development pull the repository. The prerequisite is [Docker](https://www.docker.com/) installed.

Go to the repository root folder and run `docker compose up` that will start a local [FreshRSS](https://www.freshrss.org/) instance running `http://localhost:8080/`.

Complete it's installation and navigate to Extensions, where you have to enable `Karakeep Button`.

All changes in the PHP files are loaded with each page refresh.

## Credits

This extension is a fork of [xExtension-karakeep-button](https://github.com/veverkap/xExtension-karakeep-button) by [veverkap](https://github.com/veverkap).

That extension is in turn based on [xExtension-readeck-button](https://github.com/Joedmin/xExtension-readeck-button) and re-branded for Karakeep.

Thank you very much [Joedmin](https://github.com/Joedmin) for creating the Readeck extension.
