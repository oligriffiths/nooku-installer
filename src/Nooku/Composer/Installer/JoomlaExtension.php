<?php
/**
 * Nooku Installer plugin - https://github.com/nooku/nooku-composer
 *
 * @copyright    Copyright (C) 2011 - 2013 Johan Janssens and Timble CVBA. (http://www.timble.net)
 * @license      GNU GPLv3 <http://www.gnu.org/licenses/gpl.html>
 * @link         https://github.com/nooku/nooku-composer for the canonical source repository
 */

namespace Nooku\Composer\Installer;

use Nooku\Composer\Joomla\Application as Application;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Installer\LibraryInstaller;

/**
 * Installer class to install regular extensions into a Joomla site.
 *
 * @author  Steven Rombauts <https://github.com/stevenrombauts>
 * @package Nooku\Composer\Installer
 */
class JoomlaExtension extends LibraryInstaller
{
    protected $_package_prefixes = array('com', 'plg', 'mod', 'tpl', 'pkg', 'file', 'lib', 'lng');

    protected $_application = null;
    protected $_credentials = array();
    protected $_config      = null;

    public function __construct(IOInterface $io, Composer $composer, $type = 'library')
    {
        parent::__construct($io, $composer, $type);

        $this->_config = $composer->getConfig();

        $this->_initialize();
    }

    /**
     * Initializes extension installer.
     *
     * @return void
     */
    protected function _initialize()
    {
        $config = $this->_config->get('joomla');

        if(is_null($config) || !is_array($config)) {
            $config = array();
        }

        $defaults = array(
            'name' => 'root',
            'username'  => 'root',
            'groups'    => array(8),
            'email'     => 'root@localhost.home'
        );

        $this->_credentials = array_merge($defaults, $config);

        $this->_bootstrap();
        $this->_loadKoowaPlugin();
    }

    public function install(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        if (!$this->_isValidName($package->getPrettyName())) {
            throw new \InvalidArgumentException('Invalid package name `'.$package->getPrettyName().'`. Name should be of the format `vendor/xyz_name`, where xyz is a valid Joomla extension type (' . implode(', ', $this->_package_prefixes) . ').');
        }

        parent::install($repo, $package);

        if(!$this->_application->install($this->getInstallPath($package)))
        {
            // Get all error messages that were stored in the message queue
            $descriptions = $this->_getApplicationMessages();

            $error = 'Error while installing '.$package->getPrettyName();
            if(count($descriptions)) {
                $error .= ':'.PHP_EOL.implode(PHP_EOL, $descriptions);
            }

            throw new \RuntimeException($error);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function update(InstalledRepositoryInterface $repo, PackageInterface $initial, PackageInterface $target)
    {
        if (!$this->_isValidName($target->getPrettyName())) {
            throw new \InvalidArgumentException('Invalid package name `'.$package->getPrettyName().'`. Name should be of the format `vendor/xyz_name`, with xyz a valid Joomla extension type (' . implode(', ', $this->_package_prefixes) . ').');
        }

        parent::update($repo, $initial, $target);

        if(!$this->_application->update($this->getInstallPath($target)))
        {
            // Get all error messages that were stored in the message queue
            $descriptions = $this->_getApplicationMessages();

            $error = 'Error while updating '.$target->getPrettyName();
            if(count($descriptions)) {
                $error .= ':'.PHP_EOL.implode(PHP_EOL, $descriptions);
            }

            throw new \RuntimeException($error);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function isInstalled(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        $installer = $this->_application->getInstaller();
        $installer->setPath('source', $this->getInstallPath($package));

        $manifest = $installer->getManifest();

        if($manifest)
        {
            $type    = (string) $manifest->attributes()->type;
            $element = $this->_getElementFromManifest($manifest);

            return !empty($element) ? $this->_application->hasExtension($element, $type) : false;
        }

        return false;
    }

    /**
     * Fetches the enqueued flash messages from the Joomla application object.
     *
     * @return array
     */
    protected function _getApplicationMessages()
    {
        $messages       = $this->_application->getMessageQueue();
        $descriptions   = array();

        foreach($messages as $message)
        {
            if($message['type'] == 'error') {
                $descriptions[] = $message['message'];
            }
        }

        return $descriptions;
    }

    /**
     * Load the element name from the installation manifest.
     *
     * @param $manifest
     * @return mixed|string
     */
    protected function _getElementFromManifest($manifest)
    {
        $element    = '';
        $type       = (string) $manifest->attributes()->type;

        switch($type)
        {
            case 'module':
                if(count($manifest->files->children()))
                {
                    foreach($manifest->files->children() as $file)
                    {
                        if((string) $file->attributes()->module)
                        {
                            $element = (string) $file->attributes()->module;
                            break;
                        }
                    }
                }
                break;
            case 'plugin':
                if(count($manifest->files->children()))
                {
                    foreach($manifest->files->children() as $file)
                    {
                        if ((string) $file->attributes()->$type)
                        {
                            $element = (string) $file->attributes()->$type;
                            break;
                        }
                    }
                }
                break;
            case 'component':
            default:
                $element = strtolower((string) $manifest->name);
                $element = preg_replace('/[^A-Z0-9_\.-]/i', '', $element);

                if(substr($element, 0, 4) != 'com_') {
                    $element = 'com_'.$element;
                }
                break;
        }

        return $element;
    }

    /**
     * Bootstraps the Joomla application
     *
     * @return void
     */
    protected function _bootstrap()
    {
        if(!defined('_JEXEC'))
        {
            $_SERVER['HTTP_HOST']   = 'localhost';
            $_SERVER['HTTP_USER_AGENT'] = 'Composer';

            define('_JEXEC', 1);
            define('DS', DIRECTORY_SEPARATOR);

            define('JPATH_BASE', realpath('.'));
            require_once JPATH_BASE . '/includes/defines.php';

            require_once JPATH_BASE . '/includes/framework.php';
            require_once JPATH_LIBRARIES . '/import.php';

            require_once JPATH_LIBRARIES . '/cms.php';
        }

        if(!($this->_application instanceof Application))
        {
            $options = array('root_user' => $this->_credentials['username']);

            $this->_application = new Application($options);
            $this->_application->authenticate($this->_credentials);
        }
    }

    protected function _isValidName($packageName)
    {
        list(, $name)   = explode('/', $packageName);

        if (is_null($name) || empty($name)) {
            return false;
        }

        list($prefix, ) = explode('_', $name, 2);

        return in_array($prefix, $this->_package_prefixes);
    }

    /**
     * Initializes the Koowa plugin
     */
    protected function _loadKoowaPlugin()
    {
        if (class_exists('Koowa')) {
            return;
        }

        $path = JPATH_PLUGINS . '/system/koowa/koowa.php';

        if (!file_exists($path)) {
            return;
        }

        require_once $path;

        $dispatcher = \JEventDispatcher::getInstance();
        new \PlgSystemKoowa($dispatcher, array());
    }

    public function __destruct()
    {
        if(!defined('_JEXEC')) {
            return;
        }

        // Clean-up to prevent PHP calling the session object's __destruct() method;
        // which will burp out Fatal Errors all over the place because the MySQLI connection
        // has already closed at that point.
        $session = \JFactory::$session;
        if(!is_null($session) && is_a($session, 'JSession')) {
            $session->close();
        }
    }
}