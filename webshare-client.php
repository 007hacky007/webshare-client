#!/usr/bin/env php
<?php
try {
    $c = new client();
} catch (Exception $e){
    die($e->getMessage());
}

class client {
    private $console;
    private $ws;
    private $params;

    const WS_URL = 'https://webshare.cz/';

    /**
     * client constructor.
     * @throws Exception
     */
    public function __construct()
    {
        $this->console = new console();
        $this->ws = new webshare();
        $this->params = $this->console->getParams();
        if(isset($this->params['search'])){
            $this->search($this->params['search']);
            die();
        }else{
            $this->download();
            die();
        }
    }

    /**
     * @throws Exception
     */
    private function download()
    {
        $urls = array_filter(static::getUrlsFromFile($this->params['filename']));
        if($urls < 1) throw new Exception('No valid URLs given.');
        $this->ws->setUsername($this->params['username'])
            ->setPassword($this->params['password'])
            ->login();
        if(isset($this->params['directory']))
            $this->ws->setDlPath($this->params['directory']);
        foreach ($urls as $url)
            $this->ws->downloadFile($url);
    }

    private function getUrlsFromFile(string $filename): array
    {
        if(($urls = file($filename)) === false) throw new Exception('Could not read file: ' . $filename);

        return $urls;
    }

    /**
     * @param string $term
     * @throws Exception
     */
    private function search(string $term)
    {
        $s = $this->ws->search($term);
        foreach ($s as $file) {
            echo static::createWsUrl($file->ident, $file->name) . PHP_EOL;
        }
    }

    private static function createWsUrl(string $ident, string $name): string
    {
        $modifiedName = preg_replace('/[^a-zA-Z0-9 -\.]/', '_', iconv('UTF-8', 'ASCII//TRANSLIT', $name));
        return static::WS_URL . 'file/' . $ident . '/' . $modifiedName;
    }
}

class console {
    private $params;

    /**
     * console constructor.
     * @throws Exception
     */
    public function __construct()
    {
        if(!static::isCommandLineInterface()) throw new Exception('This script is intended to be started from command line.');
        $this->getParameters();
        $this->checkRequiredParams();
    }

    public function getParams()
    {
        return $this->params;
    }

    private function checkRequiredParams()
    {
        if(isset($this->params['help'])) static::printHelp();
        if(isset($this->params['search'])) return;
        if(!isset($this->params['username']) || !isset($this->params['password']) || !isset($this->params['filename']))
            static::printHelp();
    }

    private static function printHelp()
    {
        global $argv;
        echo <<<EOL
Usage: 
    $argv[0] --username <username> --password <password> --input-file <path> [--directory <dir>]
    $argv[0] -s <term> | --search <term>
    $argv[0] -h | --help

Options:
    -h, --help                                  Show this screen.
    -u <username>, --username <username>        Set Webshare username
    -p <password>, --password <password>        Set Webshare password
    -f <file>, --input-file <file>              Set input file containing Webshare URLs to download
    -d <dir>, --directory <dir>                 Output directory [default: ./download]
EOL;
        die();

    }

    private function getParameters()
    {
        $params = getopt('hu:p:f:s:d:',
            ['help', 'username:', 'password:', 'input-filename:', 'search:', 'directory:']);

        $this->params['username'] = (!isset($params['u'])) ? !isset($params['username']) ? null : $params['username'] : $params['u'];
        $this->params['password'] = (!isset($params['p'])) ? !isset($params['password']) ? null : $params['password'] : $params['p'];
        $this->params['search'] = (!isset($params['s'])) ? !isset($params['search']) ? null : $params['search'] : $params['s'];
        $this->params['filename'] = (!isset($params['f'])) ? !isset($params['input-filename']) ? null : $params['input-filename'] : $params['f'];
        $this->params['directory'] = (!isset($params['d'])) ? !isset($params['directory']) ? null : $params['directory'] : $params['d'];
        $this->params['help'] = (!isset($params['h'])) ? !isset($params['help']) ? null : $params['help'] : $params['h'];
    }

    private static function isCommandLineInterface()
    {
        return (php_sapi_name() === 'cli');
    }
}

class webshare {
    const DS = DIRECTORY_SEPARATOR; // cross-OS compatibility
    const WS_API_URL = 'https://webshare.cz/api/';
    const TEMP_FILENAME = self::DS . 'tmpfile';
    const TEMP_FILENAME_HEADERS = self::DS . 'tmpfileHeaders';
    const WS_SEARCH_LIMIT = 1000;
    const CURL_BUFFER_SIZE = 10485760; // 10MB in bytes

    private $username;
    private $password;
    private $dlPath = '.' . self::DS . 'download';
    private $token;

    static $previousProgress = 0;

    /**
     * webshareClient constructor.
     * @throws Exception when class requirements are not met
     */
    public function __construct()
    {
        if (!function_exists('simplexml_load_string')) throw new Exception('Please install php-xml.');
    }

    /**
     * @param string $dlPath
     * @throws Exception when filename could not be gathered from the response headers
     */
    private static function renameTempFileToCorrectFilename(string $dlPath): void
    {
        if (preg_match('/^Content-Disposition: .*filename\*?=(UTF-8)?(\'\')?([^ \s]+)\s/m',
            file_get_contents($dlPath . static::DS . static::TEMP_FILENAME_HEADERS . posix_getpid()),
            $matches,
            PREG_OFFSET_CAPTURE,
            0
        )) {
            if (isset($matches[1][0]) && $matches[1][0] === 'UTF-8') {
                $filename = urldecode($matches[3][0]);
            } else {
                $filename = $matches[3][0];
            }
            rename($dlPath . static::DS . static::TEMP_FILENAME . posix_getpid(), $dlPath . static::DS . $filename);
        } else {
            throw new Exception('Could not get filename from response headers.');
        }
    }

    public function setUsername(string $username)
    {
        $this->username = $username;
        return $this;
    }

    public function setPassword(string $password)
    {
        $this->password = $password;
        return $this;
    }

    public function setDlPath(string $dlPath)
    {
        $this->dlPath = $dlPath;
        return $this;
    }

    /**
     * @throws Exception when username and/or password is not set
     */
    public function login()
    {
        if(!isset($this->username) || !isset($this->password)) throw new Exception('Please set username and password before logging in.');
        $s = $this->callWsApi('salt', ['username_or_email' => $this->username]);
        $salt = $s->salt;
        $d = static::getPassDigest($this->username, $this->password, $salt);
        $l = $this->callWsApi('login',[
           'username_or_email' => $this->username,
           'password' => $d->password,
           'digest' => $d->digest,
           'keep_logged_in' => 1,
           'wst' => '',
        ]);
        $this->token = (string)$l->token;
    }

    /**
     * @param string $path
     * @throws Exception in case of directory creation failure
     */
    private static function createDlFolder(string $path)
    {
        if(is_dir($path)) return; // folder already exists
        if(mkdir($path, 0777, true) === false) throw new Exception('Could not create directory: ' . $path);
    }

    /**
     * @param string $what
     * @return SimpleXMLElement
     * @throws Exception
     */
    public function search(string $what): SimpleXMLElement
    {
        $s = $this->callWsApi('search', [
            'what' => $what,
            'category' => '',
            'sort' => '',
            'offset' => 0,
            'limit' => static::WS_SEARCH_LIMIT,
        ]);

        return $s->file;
    }

    /**
     * @param string $url
     * @throws Exception
     */
    public function downloadFile(string $url)
    {
        if(!isset($this->token)) throw new Exception('Please login to the WS first.');
        static::createDlFolder($this->dlPath);
        $link = $this->getFileLink($url);

        $targetFileName = $this->dlPath . static::TEMP_FILENAME . posix_getpid();
        $headerFileName = $this->dlPath . static::TEMP_FILENAME_HEADERS . posix_getpid();

        if(($targetFile = fopen($targetFileName, 'w')) === false) throw new Exception('Could not create temp file: ' . $targetFileName);
        if(($headerBuff = fopen($headerFileName, 'w')) === false) throw new Exception('Could not create temp file: ' . $headerFileName);

        echo "Downloading $url ...\n";

        $ch = curl_init($link);
        curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, [$this, 'progressCallback']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_NOPROGRESS, false);
        curl_setopt($ch, CURLOPT_WRITEHEADER, $headerBuff);
        curl_setopt($ch, CURLOPT_FILE, $targetFile);
        curl_setopt($ch, CURLOPT_BUFFERSIZE, 10485760);
        curl_exec($ch);
        fclose($targetFile);
        fclose($headerBuff);
        static::$previousProgress = 0;

        if(!curl_errno($ch)) {
            self::renameTempFileToCorrectFilename($this->dlPath);
            unlink($headerFileName);
        }else{
            throw new Exception(curl_error($ch));
        }


        curl_close($ch);
    }

    private static function progressCallback($resource, $download_size, $downloaded_size, $upload_size, $uploaded_size)
    {
        if ($download_size == 0)
            $progress = 0;
        else
            $progress = round($downloaded_size * 100 / $download_size);

        if ($progress > static::$previousProgress) {
            static::$previousProgress = $progress;
            echo $progress . "%\r";
        }
    }

    private static function getPassDigest(string $username, string $password, string $salt): stdClass
    {
        $r = new stdClass();
        $r->password = sha1(crypt($password, '$1$' . $salt));
        $r->digest = md5($username . ':Webshare:' . $password);

        return $r;
    }


    /**
     * @param string $url
     * @return string
     * @throws Exception
     */
    private static function getFileIdent(string $url): string
    {
        preg_match('/file\/([0-9a-zA-Z]{3,})\//m', $url, $matches, PREG_OFFSET_CAPTURE, 0);
        if(!isset($matches[1][0])) throw new Exception('Could not get file ident from url: ' . $url);

        return $matches[1][0];
    }

    /**
     * @param string $endpoint
     * @param array $params
     * @return SimpleXMLElement
     * @throws Exception in case of bad params or other failure
     */
    private function callWsApi(string $endpoint, array $params): SimpleXMLElement
    {
        if(!isset($endpoint) || !isset($params)) throw new Exception('API endpoint or params not set');

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, static::WS_API_URL . $endpoint . '/');
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $serverOutput = curl_exec($ch);

        curl_close ($ch);

        $parsedResponse = static::parseXml($serverOutput);
        if((string)$parsedResponse->status !== 'OK') throw new Exception('Wrong API response: ' . print_r($parsedResponse, true));

        return $parsedResponse;
    }


    /**
     * @param string $xmlString
     * @return SimpleXMLElement
     * @throws Exception when xml can not be parsed
     */
    private static function parseXml(string $xmlString): SimpleXMLElement
    {
        libxml_use_internal_errors(true);

        $xml = simplexml_load_string($xmlString);
        if ($xml === false) {
            $errors = [];
            foreach(libxml_get_errors() as $error) {
                $errors[] = $error->message;
            }
            throw new Exception('Failed to parse xml: ' . implode(', ', $errors));
        } else {
            return $xml;
        }
    }

    /**
     * @param string $url
     * @return string
     * @throws Exception
     */
    private function getFileLink(string $url): string
    {
        $ident = static::getFileIdent($url);
        $u = $this->callWsApi('file_link', [
            'ident' => $ident,
            'password' => '',
            'wst' => $this->token,
        ]);
        $fileLink = (string)$u->link;

        return $fileLink;
    }
}