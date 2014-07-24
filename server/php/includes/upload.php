<?php

class Upload {
    
    const UPLOAD_UNAUTHORISED = 401;
    const UPLOAD_FAILED = 505;
    
    const ADHOC_FILE = "adhoc";
    const STORE_FILE = "store";
    const ICON_FILE = "icon";
    const RELEASENOTES_FILE = "releasenotes";
    
    private $_arguments;
    private $_metadata;
    private $_baseDirectory;
    private $_baseURL;
    private $_detectedPlatform;
    private $_requiredFields = array(
        Device::iOS     => array("bundleid", "icon", "version", "title", "location"),
        Device::Android => array("icon", "version", "title", "location")
    );
    
    public function __construct($baseDirectory, $baseURL, $arguments) {
        $this->_baseDirectory = $baseDirectory;
        $this->_baseURL = $baseURL;
        $this->_arguments = $arguments;
        $this->_metadata = json_decode($this->_arguments["metadata"], true);
    }
    
    public function receive() {
        if ($this->requiresAuthentication()) {
            header('WWW-Authenticate: Basic realm="' . UPLOAD_REALM . '"');
            header('HTTP/1.0 401 Unauthorized');
            return Helper::sendJSONAndExit(array("status" => self::UPLOAD_UNAUTHORISED));
        }
        else {
            try {
                $this->detectPlatform();
                $this->createTargetDirectory();
                $this->createMetadata();
                $files = $this->moveFiles();
                return Helper::sendJSONAndExit($files);
            } catch (Exception $exception) {
                return Helper::sendJSONAndExit(array(
                    "status"    => self::UPLOAD_FAILED,
                    "message"   => $exception->getMessage()
                ));
            }
        }
    }
    
    private function requiresAuthentication() {
        $credentialsProvided = isset($_SERVER['PHP_AUTH_USER']);
        $validCredentials = $_SERVER['PHP_AUTH_USER'] == UPLOAD_AUTH_USERNAME && 
                            sha1($_SERVER['PHP_AUTH_PW']) == UPLOAD_AUTH_HASH;
        return !$credentialsProvided || !$validCredentials;
    }
    
    private function detectPlatform() {
        $build = isset($_FILES[self::ADHOC_FILE]) ? $_FILES[self::ADHOC_FILE] : $_FILES[self::STORE_FILE];
        $extension = "." . pathinfo($build["name"], PATHINFO_EXTENSION);
        
        if ($extension == AppUpdater::FILE_IOS_IPA) {
            $this->_detectedPlatform = Device::iOS;
        }
        else if ($extension == AppUpdater::FILE_ANDROID_APK) {
            $this->_detectedPlatform = Device::Android;
        }
        else {
            throw new UnexpectedValueException("Unknown app type $extension");
        }
        
        $providedFields = array_merge(array_keys($_FILES), array_keys($this->_metadata));
        $missingFields = array_diff($this->_requiredFields[$this->_detectedPlatform], $providedFields);
        if (count($missingFields) > 0) {
            throw new UnexpectedValueException("Required fields were not provided - " . join(", ", $missingFields));
        }
    }
    
    private function createTargetDirectory() {
        $this->createDirectory($this->path(), "Unable to create target directory");
        $this->removePackageFromDirectory($this->path());
    }
    
    private function createMetadata() {
        $file = null;
        if ($this->_detectedPlatform == Device::iOS) {
            $file = "app.plist";
        }
        else if ($this->_detectedPlatform == Device::Android) {
            $file = "android.json";
        }
        
        $template = new view("metadata/$file");
        
        $replacements = array_merge($this->_metadata, array(
            "icon"  => $_FILES[self::ICON_FILE]["name"]
        ));
        
        $template->replaceAll($replacements);
        
        $location = "{$this->path()}/$file";

        file_put_contents($location, $template);
        
        touch("{$this->path()}/private");
    }
    
    private function moveFiles() {
        $apps = array();
        
        // Move icon
        move_uploaded_file($_FILES[self::ICON_FILE]["tmp_name"], "{$this->path()}/" . $_FILES[self::ICON_FILE]["name"]);
        
        // Move adhoc build
        if (isset($_FILES[self::ADHOC_FILE])) {
            $apps[self::ADHOC_FILE] = $this->publishAppPackage($_FILES[self::ADHOC_FILE]);
        }
        
        // Move store build
        if (isset($_FILES[self::STORE_FILE])) {
            $this->createDirectory("{$this->path()}/store/", "Unable to create store directory");
            $apps[self::STORE_FILE] = $this->publishAppPackage($_FILES[self::STORE_FILE], "store/", "%s%s/store/%s");
        }
        
        // Move releasenotes
        if (isset($_FILES[self::RELEASENOTES_FILE])) {
            move_uploaded_file($_FILES[self::RELEASENOTES_FILE]["tmp_name"], "{$this->path()}/" . $_FILES[self::RELEASENOTES_FILE]["name"]);
        }
        
        return $apps;
    }
    
    private function path() {
        return $this->sanitisePath($this->_baseDirectory, $this->_metadata["location"]);
    }
    
    private function removePackageFromDirectory($path) {
        foreach (new DirectoryIterator($path) as $fileInfo) {
            $validFile = $fileInfo->isFile() || 
                         ($fileInfo->isDir() && $fileInfo->getFilename() == "store");
            if(!$fileInfo->isDot() || $validFile) {
                unlink($fileInfo->getPathname());
            }
        }
    }
    
    private function createDirectory($path, $failureMessage) {
        if (!is_dir($path)) {
            if (!mkdir($path, 01770, true)) {
                throw new RuntimeException($failureMessage);
            }
        }
    }
    
    private function publishAppPackage($file, $location = null, $format = "%sapps/%s/%s") {
        move_uploaded_file($file["tmp_name"], "{$this->path()}/" . $location . $file["name"]);
        return sprintf($format, $this->_baseURL, $this->_metadata["location"], $file["name"]);
    }
    
    private function sanitisePath($baseDirectory, $location) {
        $path = $baseDirectory . $location;
        if (strpos($path, "..") !== false ||
            strlen(trim($location)) == 0) {
            throw new InvalidArgumentException("Invalid path provided");
        }
        return $path;
    }
}

?>