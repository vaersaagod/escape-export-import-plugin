# Escape Export & Import

Copies entries and categories, with their assets, between Craft CMS sites that share a content model.

On the source site, select elements in the element index and use **Copy URL for import**, or copy the export URL from an
element's edit page. On the target site, paste the URL under **Import** in the control panel. The target site fetches
the elements, downloads their assets and saves them in a queue job.

## Requirements

Craft CMS 5.8 or later.

## Setup

Copy `src/config.php` to `config/escape-exportimport.php` on every site, and set:

- `exportableSections` and `exportableCategoryGroups`: what can be exported from this site.
- `token`: a shared secret, at least 32 characters, that sites send each other when fetching exports. It has to be the
  same on every site. Defaults to the `ESCAPE_EXPORTIMPORT_TOKEN` environment variable.
- `sourceHosts`: the hosts this site can import from, for example `['www.example.com']`. Imports only fetch HTTPS
  URLs on these hosts, and the token is never sent anywhere else. The sample config reads a comma-separated list from
  the `ESCAPE_EXPORTIMPORT_SOURCE_HOSTS` environment variable.

```bash
ESCAPE_EXPORTIMPORT_TOKEN="<openssl rand -hex 32>"
ESCAPE_EXPORTIMPORT_SOURCE_HOSTS="www.example.com,www.example.org"
```

The export endpoints answer requests that send the token, and logged-in users with the **Import elements** permission.

Brought to you by [Værsågod](https://vaersaagod.no)
