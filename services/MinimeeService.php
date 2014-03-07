<?php
namespace Craft;

/**
 * Minimee by John D Wells
 *
 * @package   Minimee
 * @author    John D Wells
 * @copyright Copyright (c) 2012, John D Wells
 * @link      http://johndwells.com
 */

/**
 * Our plugin Service
 */
class MinimeeService extends BaseApplicationComponent
{
    protected $_assets                  = array();
    protected $_type                    = '';
    protected $_cacheFilename           = '';       // lastmodified value for cache
    protected $_cacheFilenameHash       = '';       // a hash of all asset filenames together
    protected $_cacheTimestamp          = '';       // eventual filename of cache
    protected $_config                  = null;

    // --------------------------

    public function init()
    {
        // make sure the rest of the component initialises first
        parent::init();

        $this->setConfig();
    }

    public function getAssets()
    {
        return $this->_assets;
    }

    public function getCacheFilename()
    {
        if( ! $this->_cacheFilename)
        {
            $this->_cacheFilename = $this->cacheFilenameHash . '.' . $this->cacheTimestamp . '.' . $this->type;
        }

        return $this->_cacheFilename;
    }

    public function getCacheFilenameHash()
    {
        return sha1($this->_cacheFilenameHash);
    }

    public function getCacheFilenameHashPath()
    {
        return $this->config->cachePath . $this->cacheFilenameHash;
    }

    public function getCacheFilenamePath()
    {
        return $this->config->cachePath . $this->cacheFilename;
    }

    public function getCacheFilenameUrl()
    {
        return $this->config->cacheUrl . $this->cacheFilename;
    }

    public function getCacheTimestamp()
    {
        return ($this->_cacheTimestamp == 0) ? '0000000000' : $this->_cacheTimestamp;
    }

    public function getConfig()
    {
        return $this->_config;
    }

    public function getType()
    {
        return $this->_type;
    }

    public function setAssets($assets)
    {
        foreach($assets as $asset)
        {
            if (craft()->minimee_helper->isUrl($asset))
            {
                $model = array(
                    'filename' => $asset,
                    'type' => $this->type
                );

                $this->_assets[] = Minimee_RemoteAssetModel::populateModel($model);
            }
            else
            {
                $model = array(
                    'filename' => $this->config->basePath . $asset,
                    'type' => $this->type
                );

                $this->_assets[] = Minimee_LocalAssetModel::populateModel($model);
            }
        }

        return $this;
    }

    public function setCacheTimestamp(DateTime $lastTimeModified)
    {
        $timestamp = $lastTimeModified->getTimestamp();
        $this->_cacheTimestamp = max($this->cacheTimestamp, $timestamp);
    }

    public function setCacheFilenameHash($name)
    {
        // remove any cache-busting strings so the cache name doesn't change with every edit.
        // format: .v.1330213450
        $this->_cacheFilenameHash .= preg_replace('/\.v\.(\d+)/i', '', $name);
    }

    /**
     * Configure our service based off the settings in plugin,
     * allowing plugin settings to be overridden at runtime.
     * @param Array $configOverrides
     * @return void
     */
    public function setConfig($configOverrides = array())
    {
        $plugin = craft()->plugins->getPlugin('minimee');

        $pluginSettings = $plugin->getSettings()->getAttributes();

        $runtimeSettings = (is_array($configOverrides)) ? array_merge($pluginSettings, $configOverrides) : $pluginSettings;

        $this->_config = Minimee_ConfigModel::populateModel($runtimeSettings);

        return $this;
    }

    public function setType($type)
    {
        $this->type = $type;

        return $this;
    }

    public function run($assets, $type)
    {
        $assets = (is_array($assets)) ? $assets : array($assets);

        try
        {
            return $this->reset()
                        ->setType($type)
                        ->setAssets($assets)
                        ->flightcheck()
                        ->checkHeaders()
                        ->cache();
        }
        catch (Exception $e)
        {
            return $this->_abort($e);
        }
    }

    public function css($assets)
    {
        return $this->run($assets, 'css');
    }

    public function js($assets)
    {
        return $this->run($assets, 'js');
    }

    protected function _abort($e)
    {
        Craft::log($e, LogLevel::Warning);

        // re-throw the exception if in devMode
        if(craft()->config->get('devMode'))
        {
            throw new Exception($e);
        }

        return false;
    }

    public function reset()
    {
        $this->_assets                  = array();
        $this->_type                    = '';
        $this->_cacheFilename           = '';
        $this->_cacheFilenameHash       = '';
        $this->_cacheTimestamp          = '';

        return $this;
    }

    public function flightcheck()
    {
        if ($this->config === null)
        {
            throw new Exception(Craft::t('Not installed.'));
        }

        if($this->config->disable)
        {
            throw new Exception(Craft::t('Disabled via config.'));
        }

        IOHelper::ensureFolderExists($this->config->cachePath);
        if( ! IOHelper::isWritable($this->config->cachePath))
        {
            throw new Exception(Craft::t('Cache folder is not writable: ' . $this->config->cachePath));
        }

        return $this;
    }

    public function checkHeaders()
    {
        foreach($this->assets as $asset)
        {
            if( ! $asset->exists())
            {
                throw new Exception(Craft::t($asset->filename . ' could not be found.'));
            }
        }

        return $this;
    }

    public function minifyAsset($asset)
    {
        switch ($asset->type) :
            
            case 'js':

                craft()->minimee_helper->loadLibrary('jsmin');
                $contents = \JSMin::minify($asset->contents);

            break;
            
            case 'css':

                craft()->minimee_helper->loadLibrary('css_urirewriter');
                $contents = \Minify_CSS_UriRewriter::prepend($asset->contents, $this->config->baseUrl);

                craft()->minimee_helper->loadLibrary('minify');
                $contents = \Minify_CSS::minify($contents);

            break;

        endswitch;

        return $contents;
    }

    public function cache()
    {
        if( ! $this->cacheExists())
        {
            $this->createCache();
        }

        return $this->cacheFilenameUrl;
    }

    public function createCache()
    {
        // the eventual contents of our cache
        $contents = '';
        
        foreach($this->assets as $asset)
        {
            $contents .= craft()->minimee->minifyAsset($asset);
        }

        IOHelper::writeToFile($this->cacheFilenamePath, $contents);

        $this->cleanupCache();
    }

    public function cleanupCache()
    {
        // only run cleanup if in devmode...?
        if( ! craft()->config->get('devMode')) return;

        $files = IOHelper::getFiles($this->config->cachePath);

        foreach($files as $file)
        {
            // skip self
            if ($file === $this->cacheFilenamePath) continue;

            if (strpos($file, $this->cacheFilenameHashPath) === 0)
            {
                // suppress errors by passing true as second parameter
                IOHelper::deleteFile($file, true);
            }
        }
    }

    public function cacheExists()
    {
        // loop through our files once
        foreach ($this->assets as $asset)
        {
            $this->cacheTimestamp = $asset->lastTimeModified;
            $this->cacheFilenameHash = $asset->filename;
        }

        if( ! IOHelper::fileExists($this->cacheFilenamePath))
        {
            return false;
        }

        return true;
    }
}
