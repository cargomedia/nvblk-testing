#!/bin/bash -e

VERSION=${1}  #Default value?
echo "Installing php extension: NewRelic ${VERSION}"

# Add and trust New Relic repository
echo 'deb http://apt.newrelic.com/debian/ newrelic non-free' | tee /etc/apt/sources.list.d/newrelic.list
wget -O- https://download.newrelic.com/548C16BF.gpg | apt-key add -
apt-get update
apt-get install -yy newrelic-php5
# There is a binary installer to be executed that installs a link to the proper library, depending on the php (and kernel?) version
mv /usr/lib/newrelic-php5/agent/x64/newrelic-20190902.so /usr/local/lib/php/extensions/no-debug-non-zts-20190902/newrelic.so
