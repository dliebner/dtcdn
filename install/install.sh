#!/bin/bash

# -e: Exit immediately if any command exits with a non-zero status
# -x: Print each command before it's executed
set -ex

# init local files
mkdir -p ../local
mkdir -p ../logs
mkdir -p ../transcoding
echo "<?php

if( !defined('IN_SCRIPT') ) die('Hacking attempt');
" | sudo tee ../local/constants.php > /dev/null

# chmod dirs
chmod 0777 ../www/v ../logs ../transcoding

# chmod files
chmod +x *.sh
chmod +x ../scripts/*
chmod +x ../daemon/*
#chmod +x ../cron/*.sh
chmod +x ../devel/cpp-redis-pipe/*.sh

# general dependencies
sudo apt update
sudo apt install apt-transport-https ca-certificates curl gnupg lsb-release perl libnet-ssleay-perl openssl libauthen-pam-perl libpam-runtime libio-pty-perl apt-show-versions python unzip ufw zip g++ make cmake git

# webmin repo + key
echo "deb http://download.webmin.com/download/repository sarge contrib" | sudo tee /etc/apt/sources.list.d/webmin.list > /dev/null
wget -q -O- http://www.webmin.com/jcameron-key.asc | sudo apt-key add

#install webmin
sudo apt update && sudo apt install webmin

# docker repo + key
curl -fsSL https://download.docker.com/linux/ubuntu/gpg | sudo gpg --dearmor -o /usr/share/keyrings/docker-archive-keyring.gpg
echo "deb [arch=$(dpkg --print-architecture) signed-by=/usr/share/keyrings/docker-archive-keyring.gpg] https://download.docker.com/linux/ubuntu \
  $(lsb_release -cs) stable" | sudo tee /etc/apt/sources.list.d/docker.list > /dev/null

# install docker
sudo apt update && sudo apt install docker-ce docker-ce-cli containerd.io

# pull docker transcoding image
docker pull dliebner/ffmpeg-entrydefault

# install LAMP
sudo apt install apache2 php7.4 php-cli php-dev libapache2-mod-fcgid php-fpm htop php-zip php-gd php-mbstring php-curl php-xml php-pear php-bcmath php-json php-gmp php-common certbot python3-certbot-apache

# Collect installation details
read -p "CDN server hostname: " DTCDN_HOSTNAME
read -sp "MySQL root password (keep a copy handy): " DTCDN_MYSQL_ROOT_PASS
echo

# Set mysql root credentials for root user
echo "[client]
user = \"root\"
password = \"${DTCDN_MYSQL_ROOT_PASS}\"" | sudo tee /root/.my.cnf > /dev/null
sudo chmod 0600 /root/.my.cnf

# Set hostname
sudo hostnamectl set-hostname "${DTCDN_HOSTNAME}"

# Install mysql
echo "mysql-server mysql-server/root_password password $DTCDN_MYSQL_ROOT_PASS" | sudo debconf-set-selections
echo "mysql-server mysql-server/root_password_again password $DTCDN_MYSQL_ROOT_PASS" | sudo debconf-set-selections
echo 'Installing mysql ..'
sudo apt-get install mysql-server -y
sudo apt-get install mysql-client expect -y
echo "+-----------------------------+"

# secure MySQL installation
sudo mysql_secure_installation
sleep 1

# add dtcdn mysql user
MYSQL_DTCDN_USER="dtcdn_user"
read -sp "Set MYSQL_DTCDN_USER password: " MYSQL_DTCDN_PW
echo
printf -v MYSQL_DTCDN_PW "%q" "$MYSQL_DTCDN_PW" # escape the password
sudo mysql --execute="USE mysql;\
CREATE USER '${MYSQL_DTCDN_USER}'@'localhost' IDENTIFIED WITH mysql_native_password BY '${MYSQL_DTCDN_PW}';\
GRANT ALL PRIVILEGES ON *.* TO '${MYSQL_DTCDN_USER}'@'localhost';\
FLUSH PRIVILEGES;"

# Import db schema
sudo mysql < schema.sql

# set dtcdn_user pw in constants.php
echo "
define('MYSQL_DTCDN_PW', '${MYSQL_DTCDN_PW}');
" | sudo tee -a ../local/constants.php > /dev/null
unset MYSQL_DTCDN_PW
sudo service mysql restart

# install postfix + dovecot
# ./install-postfix-dovecot.sh -p "${DTCDN_MYSQL_ROOT_PASS}" -d "${DTCDN_HOSTNAME}" -b "${MYSQL_DTCDN_PW}"

# ufw rules
sudo ufw allow ssh
sudo ufw allow 'Apache Full'
sudo ufw allow 10000

echo "y" | sudo ufw enable

sudo a2enmod rewrite proxy proxy_fcgi proxy_http http2 setenvif expires headers
sudo a2enconf php7.4-fpm

# install phpMyAdmin
sudo apt install php-mysql phpmyadmin

# force SSL on phpMyAdmin
PMAACONF=/etc/phpmyadmin/apache.conf
sudo cp $PMAACONF ${PMAACONF}.original
sudo sed -i '/<Directory \/usr\/share\/phpmyadmin>/a\
\
    # dtcdn config - force SSL\
    RewriteEngine On\
    RewriteCond %{HTTPS} !=on\
    RewriteCond %{REQUEST_URI} ^/phpmyadmin\
    RewriteRule ^/?(.*) https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]\
' /etc/phpmyadmin/apache.conf

# copy apache files
sudo cp dtcdn-apache.conf /etc/apache2/sites-available/${DTCDN_HOSTNAME}.conf
sudo cp dtcdn-apache-ssl.conf /etc/apache2/sites-available/${DTCDN_HOSTNAME}-ssl.conf
sudo cp dtcdn-bw-redis-pipe.conf /etc/apache2/conf-available/

# replace occurences of DTCDN_HOSTNAME in copied conf files
sed -i "s/\$DTCDN_HOSTNAME/$DTCDN_HOSTNAME/" /etc/apache2/sites-available/${DTCDN_HOSTNAME}.conf
sed -i "s/\$DTCDN_HOSTNAME/$DTCDN_HOSTNAME/" /etc/apache2/sites-available/${DTCDN_HOSTNAME}-ssl.conf

# soft link
sudo ln -rs /etc/apache2/sites-available/${DTCDN_HOSTNAME}* /etc/apache2/sites-enabled/
sudo ln -rs /etc/apache2/conf-available/dtcdn* /etc/apache2/conf-enabled/

# replace occurences of DTCDN_HOSTNAME in source files
sed -i "s/\$DTCDN_HOSTNAME/$DTCDN_HOSTNAME/" ../devel/cpp-redis-pipe/redis-pipe.cc

# build stuff
cd /home/dtcdn/devel/cpp-redis-pipe/
./build.sh
cd /home/dtcdn/install

# copy files
sudo cp ../devel/cpp-redis-pipe/redis-pipe ../scripts/

sudo service apache2 restart

# install SSL certificate
sudo certbot --apache
sleep 1
sudo systemctl status certbot.timer

sudo service apache2 restart

# install redis
sudo apt install redis-server
sudo sed -i 's/^supervised no$/supervised systemd/g' /etc/redis/redis.conf

# install php-redis
pecl install redis
echo "extension=redis.so" | sudo tee /etc/php/7.4/mods-available/redis.ini > /dev/null
sudo ln -s /etc/php/7.4/mods-available/redis.ini /etc/php/7.4/fpm/conf.d/20-redis.ini
sudo ln -s /etc/php/7.4/mods-available/redis.ini /etc/php/7.4/cli/conf.d/20-redis.ini

# switch to mpm_event
sudo a2dismod php7.4 mpm_prefork
sudo a2enmod mpm_event

# create dtcdn user
sudo useradd -m -s /bin/bash dtcdn
# add dtcdn user to www-data group
sudo usermod -a -G www-data dtcdn
# copy bash profile stuff
sudo cp /home/dtcdn/install/.profile /home/dtcdn/
sudo cp /home/dtcdn/install/.bashrc /home/dtcdn/

# install composer
./install-composer.sh

# chown files
chown -R dtcdn:dtcdn /home/dtcdn

# install composer extensions
cd /home/dtcdn
su dtcdn -c "composer require gabrielelana/byte-units guzzlehttp/guzzle:^6.5"
# flexihash/flexihash is used on the hub server, don't think we need it here
# obregonco/backblaze-b2 - the original b2 repo we forked from
su dtcdn -c "composer config repositories.backblaze-b2 vcs https://github.com/dliebner/backblaze-b2"
su dtcdn -c "composer require dliebner/backblaze-b2:dev-master"

# install nvm and node
su - dtcdn -c "curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/v0.40.3/install.sh | bash"
su - dtcdn -c "nvm install --lts"

# install chromium dependencies (for puppeteer)
sudo apt install libasound2 libatk-bridge2.0-0 libatk1.0-0 libatspi2.0-0 libc6 libcairo2 libcups2 libdbus-1-3 libdrm2 libexpat1 libgbm1 libglib2.0-0 libnspr4 libnss3 libpango-1.0-0 libpangocairo-1.0-0 libstdc++6 libudev1 libuuid1 libx11-6 libx11-xcb1 libxcb-dri3-0 libxcb1 libxcomposite1 libxcursor1 libxdamage1 libxext6 libxfixes3 libxi6 libxkbcommon0 libxrandr2 libxrender1 libxshmfence1 libxss1 libxtst6

# install pm2 to run node services
su - dtcdn -c "npm install pm2 -g"

# go back to install dir
cd /home/dtcdn/install

# DNS: systemd-resolved's stub, with a cache and a fallback resolver. A provider image may ship a static
# /etc/resolv.conf naming one upstream, which means no cache and no fallback: every hub call then pays for a
# lookup, and one slow answer fails the call (the hub client's connect timeout covers the lookup). Cache=yes is
# the default on 20.04; Ubuntu 24.04 ships Cache=no-negative, which re-asks for every missing AAAA record.
# Idempotent: nothing is touched when the drop-in already matches.
RESOLVED_DROPIN=/etc/systemd/resolved.conf.d/dns.conf
RESOLVED_CONF="[Resolve]
DNS=1.1.1.1 8.8.8.8
FallbackDNS=1.0.0.1 8.8.4.4
Domains=~.
Cache=yes"
if [ ! -f "$RESOLVED_DROPIN" ] || [ "$(cat "$RESOLVED_DROPIN")" != "$RESOLVED_CONF" ]; then
	sudo mkdir -p /etc/systemd/resolved.conf.d
	echo "$RESOLVED_CONF" | sudo tee "$RESOLVED_DROPIN" > /dev/null
	# resolved first, and only then point resolv.conf at its stub, so an image without resolved keeps its DNS
	sudo systemctl enable --now systemd-resolved
	sudo systemctl restart systemd-resolved
	test -e /run/systemd/resolve/stub-resolv.conf
	sudo ln -sf /run/systemd/resolve/stub-resolv.conf /etc/resolv.conf
fi

# copy files
# the crons' log directory: dtcdn-owned and not world-writable (the repo's logs/ is 0777, where a planted symlink
# would redirect a cron's output into any file dtcdn can write)
sudo mkdir -p /var/log/dtcdn
sudo chown dtcdn:dtcdn /var/log/dtcdn
sudo chmod 0750 /var/log/dtcdn

visudo -cf dtcdn-sudoers # check before it is in place: a bad file there breaks sudo for the whole box
sudo install -m 0440 dtcdn-sudoers /etc/sudoers.d/dtcdn-sudoers
sudo cp dtcdn-cron /etc/cron.d/
sudo cp dtcdn-docker-events.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo cp dtcdn-logrotate /etc/logrotate.d/dtcdn

# chown files again
chown -R dtcdn:dtcdn /home/dtcdn/

# php-fpm pool: raise the stock pool (a handful of children) so a few uploaders plus the hub's
# once-a-second heartbeat can't fill it. Each child serves one request at a time and uses ~20 MB here,
# so 64 children is ~1.3 GB at full load. The slow log names any request over 2 s; the status page is
# served to localhost by the ssl vhost (/fpm-status). See manual-steps.txt for the reasoning.
# request_terminate_timeout counts from the first byte of the request, and Apache streams an upload
# body to php-fpm as it arrives, so it must cover the whole client upload (525M at a slow rate), not
# only the script's run time; 300s would kill a large upload on a slow connection.
FPM_POOL=/etc/php/7.4/fpm/pool.d/www.conf
sudo sed -i -E \
	-e 's/^;?listen\.backlog = .*/listen.backlog = 1024/' \
	-e 's/^pm\.max_children = .*/pm.max_children = 64/' \
	-e 's/^pm\.start_servers = .*/pm.start_servers = 16/' \
	-e 's/^pm\.min_spare_servers = .*/pm.min_spare_servers = 8/' \
	-e 's/^pm\.max_spare_servers = .*/pm.max_spare_servers = 32/' \
	-e 's/^;?pm\.max_requests = .*/pm.max_requests = 10000/' \
	-e 's/^;?pm\.status_path = .*/pm.status_path = \/fpm-status/' \
	-e 's#^;?slowlog = .*#slowlog = /var/log/php7.4-fpm.slow.log#' \
	-e 's/^;?request_slowlog_timeout = .*/request_slowlog_timeout = 2s/' \
	-e 's/^;?request_terminate_timeout = .*/request_terminate_timeout = 3600s/' \
	$FPM_POOL
# listen.backlog is capped by the kernel's somaxconn (4096 on 20.04's kernel; pin it)
echo 'net.core.somaxconn = 4096' | sudo tee /etc/sysctl.d/60-fpm.conf > /dev/null
sudo sysctl --system > /dev/null
sudo php-fpm7.4 -t

# restart apache
sudo service apache2 restart
sudo service php7.4-fpm restart

# start custom daemons
sudo systemctl enable dtcdn-docker-events.service
sudo systemctl start dtcdn-docker-events.service

# start and save pm2 services
su - dtcdn -c "pm2 start ~/node-micro/screenshotter/index.js --name 'screenshotter'"
su - dtcdn -c "pm2 save"
