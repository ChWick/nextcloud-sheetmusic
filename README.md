# Sheet Music

A sheet music manager for Nextcloud.

## Usage

- To get started easily use the [Appstore App generator](https://apps.nextcloud.com/developer/apps/generate) to
  dynamically generate an App based on this repository with all the constants prefilled.
- Alternatively you can use the "Use this template" button on the top of this page to create a new repository based on
  this repository. Afterwards adjust all the necessary constants like App ID, namespace, descriptions etc.

Once your app is ready follow the [instructions](https://nextcloudappstore.readthedocs.io/en/latest/developer.html) to
upload it to the Appstore.

## Resources

### Documentation for developers:

- General documentation and tutorials: https://nextcloud.com/developer
- Technical documentation: https://docs.nextcloud.com/server/latest/developer_manual

### Help for developers:

- Official community chat: https://cloud.nextcloud.com/call/xs25tz5y
- Official community forum: https://help.nextcloud.com/c/dev/11

## Development

The app is developed against the Nextcloud container in `.docker/docker-compose.yml`:

```bash
docker compose -f .docker/docker-compose.yml up -d
docker compose -f .docker/docker-compose.yml exec nextcloud php occ app:enable sheetmusic
npm install && npm run watch     # or: npm run build
```

The frontend is served straight from the mounted app folder, so a rebuild is enough for
JavaScript changes. PHP files are different: the container keeps the code it loaded, so a
new or changed API route (e.g. a new entry in `appinfo/routes.php`) is only known to the
server after a restart - without it the app reports the request as `HTTP 404`/`405` even
though the file on disk is up to date. `.docker/opcache-dev.ini` is mounted into the
container to make PHP changes take effect immediately; apply it once with

```bash
docker compose -f .docker/docker-compose.yml up -d --force-recreate nextcloud
```

After changing PHP files without that mount, or whenever an endpoint unexpectedly answers
with 404/405, restart the container:

```bash
docker compose -f .docker/docker-compose.yml restart nextcloud
```
