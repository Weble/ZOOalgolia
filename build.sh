# Remove old files
rm -f build/*.zip

# Zip Plugin
cd plugin/
composer install
zip -qr ../build/plg_system_zooalgolia.zip ./*

cd ../
