php /var/www/html/occ app:disable firstrunwizard
php /var/www/html/occ app:enable mobilesheetsmanager
php /var/www/html/occ log:manage --level 0
php /var/www/html/occ db:add-missing-indices
php /var/www/html/occ maintenance:repair
