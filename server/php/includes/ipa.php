<?php

class IPA {
    private $_ipa;
    private $_profile;
    
    public function __construct(SplFileObject $ipa) {
        $this->_ipa = $ipa;
        $this->read();
    }
    
    public function provisionsAllDevices() {
        return (bool)preg_match("/<key>ProvisionsAllDevices<\/key>\W+<true\/>/", $this->_profile);
    }
    
    public function provisionedDevices() {
        $matches = array();
        preg_match("/<key>ProvisionedDevices<\/key>\W+<array>(.*?)<\/array>/smu", $this->_profile, $matches);
        if (count($matches) > 0) {
            $devices = array();
            preg_match_all("/<string>(.*?)<\/string>/", $matches[1], $devices);
            return $devices[1];
        }
        return array();
    }
    
    private function read() {
        $zip = new ZipArchive();
        $file = "profile_data";
        $profile_file = $this->_ipa->getFileInfo()->getPath() . "/" . $file;
        $profile = null;
        
        if (!file_exists($profile_file)) {
            if ($zip->open($this->_ipa->getFileInfo()) === true) {
               $entry = $zip->locateName("embedded.mobileprovision", ZipArchive::FL_NODIR);
               if ($entry !== false) {
                   $profile_data = $zip->getFromIndex($entry);
                   
                   $start = strpos($profile_data, "<?xml version");
                   $end_characters = "</plist>";
                   $end = strpos($profile_data, $end_characters) - $start + strlen($end_characters);
                   $profile = substr($profile_data, $start, $end);
                   if (is_writable($profile_file)) {
                       file_put_contents($profile_file, $profile);
                   }
               }
               $zip->close();
            }
        }
        else {
            $profile = file_get_contents($profile_file);
        }
        
        $this->_profile = $profile;
    }
}

?>