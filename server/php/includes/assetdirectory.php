<?php

class AssetDirectory {
  
  private $_dir;
  private $_language;
  
  public $ipa;
  public $plist;
  public $profile;
  public $apk;
  public $json;
  public $note;
  
  
  public function __construct(Directory $dir, $language) {
      $this->_dir = $dir;
      $this->_language = $language;
      $this->parseDirectoryContents();
  }  
  
  private function parseDirectoryContents() {
      // iOS
      $this->ipa        = @array_shift(glob($this->_dir->path . '/*' . AppUpdater::FILE_IOS_IPA));
      $this->plist      = @array_shift(glob($this->_dir->path . '/*' . AppUpdater::FILE_IOS_PLIST));
      $this->profile    = @array_shift(glob($this->_dir->path . '/*' . AppUpdater::FILE_IOS_PROFILE));

      // Android
      $this->apk        = @array_shift(glob($this->_dir->path . '/*' . AppUpdater::FILE_ANDROID_APK));
      $this->json       = @array_shift(glob($this->_dir->path . '/*' . AppUpdater::FILE_ANDROID_JSON));
    
      $this->note = '';
      // Common
      if ($this->_language) {
          $this->note   = @array_shift(glob($this->_dir->path . '/*' . AppUpdater::FILE_COMMON_NOTES . '.' . $this->_language));
      }
      if (!$this->note) {
          $this->note   = @array_shift(glob($this->_dir->path . '/*' . AppUpdater::FILE_COMMON_NOTES));   // the default language file should not have a language extension, so if en is default, never creaete a .html.en file!
      }
      $this->icon       = @array_shift(glob($this->_dir->path . '/*' . AppUpdater::FILE_COMMON_ICON));
      $this->mandatory  = @array_shift(glob($this->_dir->path . '/*' . AppUpdater::FILE_VERSION_MANDATORY));    // this file defines if the version is mandatory
      $this->restrict   = @array_shift(glob($this->_dir->path . '/*' . AppUpdater::FILE_VERSION_RESTRICT));    // this file defines the teams allowed to access this version
  }
  
  
  public function getApplicationVersions($platform) {
      $files = array();

        $allVersions = array();
        
        if ((!$this->ipa || !$this->plist) && 
            (!$this->apk || !$this->json)) {
            // check if any are available in a subdirectory
            
            $subDirs = array();
            if ($handleSub = opendir($this->_dir->path)) {
                while (($fileSub = readdir($handleSub)) !== false) {
                    if (!in_array($fileSub, array('.', '..')) && 
                        is_dir($this->_dir->path . '/'. $fileSub)) {
                        array_push($subDirs, $fileSub);
                    }
                }
                closedir($handleSub);
            }

            // Sort the files and display
            usort($subDirs, array($this, 'sort_versions'));
            
            if (count($subDirs) > 0) {
                foreach ($subDirs as $subDir) {
                    $subDirectory = dir($this->_dir->path . '/'. $subDir);
                    $subAssetDir = new AssetDirectory($subDirectory, $this->_language);
          
                    if ($subAssetDir->ipa && $subAssetDir->plist && (!$platform || $platform == AppUpdater::PLATFORM_IOS)) {
                        $version = array();
                        $version[AppUpdater::FILE_IOS_IPA] = $subAssetDir->ipa;
                        $version[AppUpdater::FILE_IOS_PLIST] = $subAssetDir->plist;
                        $version[AppUpdater::FILE_COMMON_NOTES] = $subAssetDir->note;
                        $version[AppUpdater::FILE_VERSION_RESTRICT] = $subAssetDir->restrict;
                        $version[AppUpdater::FILE_VERSION_MANDATORY] = $subAssetDir->mandatory;
                        
                        // if this is a restricted version, check if the UDID is provided and allowed
                        if ($subAssetDir->restrict && !$this->checkProtectedVersion($subAssetDir->restrict)) {
                            continue;
                        }
                        
                        $allVersions[$subDir] = $version;
                    } else if ($subAssetDir->apk && $subAssetDir->json && (!$platform || $platform == AppUpdater::PLATFORM_ANDROID)) {
                        $version = array();
                        $version[AppUpdater::FILE_ANDROID_APK] = $subAssetDir->apk;
                        $version[AppUpdater::FILE_ANDROID_JSON] = $subAssetDir->json;
                        $version[AppUpdater::FILE_COMMON_NOTES] = $subAssetDir->note;
                        $allVersions[$subDir] = $version;
                    }
                }

                if (count($allVersions) > 0) {
                    $files[AppUpdater::VERSIONS_SPECIFIC_DATA] = $allVersions;
                    $files[AppUpdater::VERSIONS_COMMON_DATA][AppUpdater::FILE_IOS_PROFILE] = $subAssetDir->profile;
                    $files[AppUpdater::VERSIONS_COMMON_DATA][AppUpdater::FILE_COMMON_ICON] = $subAssetDir->icon;
                }
            }
        } else {
            $version = array();
            if ($this->ipa && $this->plist) {
                $version[AppUpdater::FILE_IOS_IPA] = $this->ipa;
                $version[AppUpdater::FILE_IOS_PLIST] = $this->plist;
                $version[AppUpdater::FILE_COMMON_NOTES] = $this->note;
                $allVersions[] = $version;
                $files[AppUpdater::VERSIONS_SPECIFIC_DATA] = $allVersions;
                $files[AppUpdater::VERSIONS_COMMON_DATA][AppUpdater::FILE_IOS_PROFILE] = $this->profile;
                $files[AppUpdater::VERSIONS_COMMON_DATA][AppUpdater::FILE_COMMON_ICON] = $this->icon;
            } else if ($this->apk && $this->json) {
                $version[AppUpdater::FILE_ANDROID_APK] = $this->apk;
                $version[AppUpdater::FILE_ANDROID_JSON] = $this->json;
                $version[AppUpdater::FILE_COMMON_NOTES] = $this->note;
                $allVersions[] = $version;
                $files[AppUpdater::VERSIONS_SPECIFIC_DATA] = $allVersions;
                $files[AppUpdater::VERSIONS_COMMON_DATA][AppUpdater::FILE_COMMON_ICON] = $this->icon;
            }
        }
        
        return $files;
  }
  
  protected function checkProtectedVersion($restrict) {
    $allowedTeams = array();
    foreach (@file($restrict) as $line) {
        if (preg_match('/^\s*#/', $line)) continue;
        $items = array_filter(array_map('trim', explode(',', $line)));
        $allowedTeams = array_merge($allowedTeams, $items);
    }

    if (!$allowedTeams) return true;

    $udid = Router::arg(AppUpdater::PARAM_2_UDID);
    if (!$udid) return false;
    
    $users = AppUpdater::parseUserList();
    if (isset($users[$udid])) {
        return count(array_intersect($users[$udid]['teams'], $allowedTeams)) > 0;
    }
    
    return false;
  }
  
  protected function sort_versions($a, $b) {
      return version_compare($a, $b, '<');
  }
    
}

?>