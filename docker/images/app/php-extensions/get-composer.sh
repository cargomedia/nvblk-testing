#!/bin/bash -e

echo 'Install composer'
curl -s https://getcomposer.org/installer | php
mv composer.phar /usr/local/bin/
