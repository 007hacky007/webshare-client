# Webshare CLI client

This is CLI PHP Webshare client to download files from Webshare.cz file-share service. 
Webshare premium account is required to use this client. 

# Installation
Just grab `webshare-client.php` and run it via `./webshare-client.php` 
or directly through PHP interpreter `php webshare-client.php`. PHP interpreter is required obviously.
If you running on debian, install it simply via `apt install php-cli` or `apt-get install php7.3-cli`. 
Tested on `PHP 7.1.10`, so should run on any newer PHP version - can't guarantee functionality on older PHP versions
thought. `php-xml` and `php-curl` libs are also required.

# Usage
```
    ./webshare-client.php --username <username> --password <password> --input-file <path> [--directory <dir>]
    ./webshare-client.php -s <term> | --search <term>
    ./webshare-client.php -h | --help

Options:
    -h, --help                                  Show this screen.
    -u <username>, --username <username>        Set Webshare username
    -p <password>, --password <password>        Set Webshare password
    -f <file>, --input-file <file>              Set input file containing Webshare URLs to download
    -d <dir>, --directory <dir>                 Output directory [default: ./download]
```
    
    
# Possible future improvements
- use cli_set_process_title() to hide WS credentials
- read password with readline from stdin, so it is not recorded in shell history
- parallel download?
- allow running post-process command (i.e. to convert downloaded video files) 