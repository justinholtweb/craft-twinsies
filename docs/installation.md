---
title: Installation
slug: installation
order: 10
summary: Requirements, install, and connecting to Twinfield.
---

## Requirements

- Craft CMS 5.3 or later
- Craft Commerce 5.0 or later
- PHP 8.2 or later
- A Twinfield subscription, and a Twinfield user who can reach the administration you want to post into

Twinsies does **not** need PHP's `soap` extension. Twinfield's web services are SOAP, but there
are only two operations worth calling and both take plain strings, so Twinsies builds the envelopes
itself over the HTTP client Craft already ships. That is deliberate — plenty of managed hosts do
not compile `ext-soap` in.

## Install

```sh
composer require justinholtweb/craft-twinsies
php craft plugin/install twinsies
```

Or find **Twinsies** in the Plugin Store and install it from the control panel.

## Register a Twinfield app

Twinsies connects over OAuth 2.0. You need an app registered in the Twinfield developer portal.

1. Open **Settings → Plugins → Twinsies** in the Craft control panel.
2. Copy the **Redirect URI** shown at the top of the screen.
3. In the Twinfield developer portal, register an app and paste that URI in as the redirect URI.
   Twinfield compares it character for character, so copy it rather than typing it.
4. Twinfield gives you a **client ID** and a **client secret**.

Make sure the app requests the `offline_access` scope. Without it Twinfield issues an access token
that lasts an hour and no refresh token, and the connection dies the first time it expires.

## Store the secret in your environment

Put the secret in `.env` and reference it from the settings screen:

```sh
# .env
TWINFIELD_CLIENT_ID="your-client-id"
TWINFIELD_CLIENT_SECRET="your-client-secret"
```

```
Client ID       $TWINFIELD_CLIENT_ID
Client secret   $TWINFIELD_CLIENT_SECRET
```

Plugin settings are project config, and project config is committed to version control. A Twinfield
refresh token is valid for roughly 25 years and grants full access to a company's books — it does
not belong in a repository.

The grant itself is never stored in project config. Twinsies keeps it in its own database table,
encrypted with your Craft security key.

## Connect

1. Save the settings screen.
2. Press **Connect to Twinfield** and authorise the app.
3. Pick your **office** — the Twinfield administration everything will post into.
4. Press **Test connection**. It should name the administration back to you.

From the command line:

```sh
php craft twinsies/auth/status     # connected, and to which cluster
php craft twinsies/auth/offices    # what this Twinfield user can reach
php craft twinsies/auth/test       # make a real request and report what came back
```

## About clusters

Twinfield runs on several clusters, and a request sent to the wrong one fails with an access error
rather than a redirect. Which cluster your organisation lives on is a claim on the validated access
token, not something you configure — Twinsies reads it during the connection and caches it. If you
ever move organisations, reconnect.

## Next

Nothing is posted until you configure it. Continue with [Configuration](configuration).
