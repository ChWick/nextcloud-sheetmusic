FROM ghcr.io/nextcloud/nextcloud-dev-php85:master

RUN cd /var/www/html
RUN sudo -u www-data php occ config:system:set trusted_domains 1 --value="*"
