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

# general dependencies assumed to be installed
# webmin assumed to be installed
# docker assumed to be installed
# LAMP assumed to be installed

# Collect dtcdn domain
read -p "DTCDN domain: " DTCDN_HOSTNAME
echo

# Mysql root credentials for root user assumed to be set
# Hostname assumed to be set
# Mysql assumed to be installed and secured

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

# ufw rules assumed to be set up
# apache mods and php7.4-fpm conf assumed to be set up
# phpMyAdmin assumed to be installed and set up

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

# redis assumed to be installed and configured
# php-redis assumed to be installed
# apache assumed to be using mpm_worker

# create dtcdn user
sudo useradd -m -s /bin/bash dtcdn
# add dtcdn user to www-data group
sudo usermod -a -G www-data dtcdn

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
cd /home/dtcdn/install

# copy files
sudo cp dtcdn-sudoers /etc/sudoers.d/
sudo cp dtcdn-cron /etc/cron.d/
sudo cp dtcdn-docker-events.service /etc/systemd/system/

# chown files again
chown -R dtcdn:dtcdn /home/dtcdn/

# restart apache
sudo service apache2 restart
sudo service php7.4-fpm restart

# start custom daemons
sudo systemctl enable dtcdn-docker-events.service
sudo systemctl start dtcdn-docker-events.service
