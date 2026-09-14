#!/bin/sh
# snmptrapd pipes each trap to this script on stdin; hand it to Laravel.
cd /var/www/html && php artisan traps:record
