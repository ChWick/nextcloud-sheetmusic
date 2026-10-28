FROM ghcr.io/nextcloud/nextcloud-dev-php85:master

# Team folders (groupfolders) app, needed by the team folder end-to-end tests.
# The app store release only supports stable server versions, so the branch
# matching the server branch of the base image is cloned instead. It is enabled
# on first start through NEXTCLOUD_AUTOINSTALL_APPS (see docker-compose.yml).
# Only the PHP backend is needed for the tests; the admin UI stays unbuilt.
ARG GROUPFOLDERS_BRANCH=master
RUN git clone --depth 1 --branch "${GROUPFOLDERS_BRANCH}" \
		https://github.com/nextcloud/groupfolders.git /var/www/html/apps-writable/groupfolders \
	&& rm -rf /var/www/html/apps-writable/groupfolders/.git \
	&& chown -R www-data:www-data /var/www/html/apps-writable
