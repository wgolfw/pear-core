<?php
//
// +----------------------------------------------------------------------+
// | PHP Version 5                                                        |
// +----------------------------------------------------------------------+
// | Copyright (c) 1997-2004 The PHP Group                                |
// +----------------------------------------------------------------------+
// | This source file is subject to version 3.0 of the PHP license,       |
// | that is bundled with this package in the file LICENSE, and is        |
// | available through the world-wide-web at the following url:           |
// | http://www.php.net/license/3_0.txt.                                  |
// | If you did not receive a copy of the PHP license and are unable to   |
// | obtain it through the world-wide-web, please send a note to          |
// | license@php.net so we can mail you a copy immediately.               |
// +----------------------------------------------------------------------+
// | Authors: Greg Beaver <cellog@php.net>                                |
// |          Stig Bakken <ssb@php.net>                                   |
// |          Tomas V.V.Cox <cox@idecnet.com>                             |
// |          Martin Jansen <mj@php.net>                                  |
// +----------------------------------------------------------------------+
//
// $Id$

require_once 'PEAR/Common.php';
require_once 'PEAR/Registry.php';
require_once 'PEAR/Dependency.php';
require_once 'PEAR/Dependency2.php';
require_once 'PEAR/DependencyDB.php';
require_once 'PEAR/Remote.php';
require_once 'PEAR/PackageFile.php';
require_once 'PEAR/Downloader/Package.php';
require_once 'System.php';


define('PEAR_INSTALLER_OK',       1);
define('PEAR_INSTALLER_FAILED',   0);
define('PEAR_INSTALLER_SKIPPED', -1);
define('PEAR_INSTALLER_ERROR_NO_PREF_STATE', 2);

/**
 * Administration class used to download PEAR packages and maintain the
 * installed package database.
 *
 * @since PEAR 1.4
 * @author Greg Beaver <cellog@php.net>
 */
class PEAR_Downloader extends PEAR_Common
{
    /**
     * @var PEAR_Registry
     * @access private
     */
    var $_registry;

    /**
     * @var PEAR_Remote
     * @access private
     */
    var $_remote;

    /**
     * Preferred Installation State (snapshot, devel, alpha, beta, stable)
     * @var string|null
     * @access private
     */
    var $_preferredState;

    /**
     * Options from command-line passed to Install.
     *
     * Recognized options:<br />
     *  - onlyreqdeps   : install all required dependencies as well
     *  - alldeps       : install all dependencies, including optional
     *  - installroot   : base relative path to install files in
     *  - force         : force a download even if warnings would prevent it
     * @see PEAR_Command_Install
     * @access private
     * @var array
     */
    var $_options;

    /**
     * Downloaded Packages after a call to download().
     *
     * Format of each entry:
     *
     * <code>
     * array('pkg' => 'package_name', 'file' => '/path/to/local/file',
     *    'info' => array() // parsed package.xml
     * );
     * </code>
     * @access private
     * @var array
     */
    var $_downloadedPackages = array();

    /**
     * Packages slated for download.
     *
     * This is used to prevent downloading a package more than once should it be a dependency
     * for two packages to be installed.
     * Format of each entry:
     *
     * <pre>
     * array('package_name1' => parsed package.xml, 'package_name2' => parsed package.xml,
     * );
     * </pre>
     * @access private
     * @var array
     */
    var $_toDownload = array();

    /**
     * Array of every package installed, with names lower-cased.
     *
     * Format:
     * <code>
     * array('package1' => 0, 'package2' => 1, );
     * </code>
     * @var array
     */
    var $_installed = array();

    /**
     * @var array
     * @access private
     */
    var $_errorStack = array();
    
    /**
     * @var boolean
     * @access private
     */
    var $_internalDownload = false;

    /**
     * Temporary variable used in sorting packages by dependency in {@link sortPkgDeps()}
     * @var array
     * @access private
     */
    var $_packageSortTree;

    /**
     * Temporary directory, or configuration value where downloads will occur
     * @var string
     */
    var $_downloadDir;
    // {{{ PEAR_Downloader()

    function PEAR_Downloader(&$ui, $options, &$config)
    {
        parent::PEAR_Common();
        $this->_options = $options;
        $this->config = &$config;
        $this->_preferredState = $this->config->get('preferred_state');
        $this->ui = &$ui;
        if (!$this->_preferredState) {
            // don't inadvertantly use a non-set preferred_state
            $this->_preferredState = null;
        }

        $php_dir = $this->config->get('php_dir');
        if (isset($this->_options['installroot'])) {
            if (substr($this->_options['installroot'], -1) == DIRECTORY_SEPARATOR) {
                $this->_options['installroot'] = substr($this->_options['installroot'], 0, -1);
            }
            $php_dir = $this->_prependPath($php_dir, $this->_options['installroot']);
        }
        $this->_registry = &new PEAR_Registry($php_dir);
        $this->_remote = &new PEAR_Remote($config);
        $this->_remote->setRegistry($this->_registry);

        if (isset($this->_options['alldeps']) || isset($this->_options['onlyreqdeps'])) {
            $this->_installed = $this->_registry->listAllPackages();
            foreach ($this->_installed as $key => $unused) {
                if (!count($unused)) {
                    continue;
                }
                @array_walk($this->_installed[$key], 'strtolower');
            }
        }
    }

    function discover($channel)
    {
        $this->log(1, 'Attempting to discover channel "' . $channel . '"...');
        PEAR::pushErrorHandling(PEAR_ERROR_RETURN);
        $callback = $this->ui ? array(&$this, '_downloadCallback') : null;
        $a = $this->downloadHttp('http://' . $channel . '/channel.xml', $this->ui, System::mktemp(array('-d')), $callback);
        PEAR::popErrorHandling();
        if (PEAR::isError($a)) {
            return false;
        }
        include_once 'PEAR/ChannelFile.php';
        $b = new PEAR_ChannelFile;
        if ($b->fromXmlFile($a)) {
            @unlink($a);
            if ($this->config->get('auto_discover')) {
                $this->_registry->addChannel($b);
                $alias = $b->getName();
                if ($b->getName() == $this->_registry->channelName($b->getAlias())) {
                    $alias = $b->getAlias();
                }
                $this->log(1, 'Auto-discovered channel "' . $channel .
                    '", alias "' . $b->getAlias() . '", adding to registry');
            }
            return true;
        }
        @unlink($a);
        return false;
    }

    function &download($params)
    {
        if (!isset($this->_registry)) {
            $this->_registry = &$this->config->getRegistry();
        }
        if (!isset($this->_remote)) {
            $this->_remote = &$this->config->getRemote();
        }
        // convert all parameters into PEAR_Downloader_Package objects
        foreach ($params as $i => $param) {
            $params[$i] = &new PEAR_Downloader_Package($this);
            PEAR::pushErrorHandling(PEAR_ERROR_RETURN);
            $err = $params[$i]->initialize($param);
            PEAR::popErrorHandling();
            if (PEAR::isError($err)) {
                $this->log(0, $err->getMessage());
                $params[$i] = false;
                if (is_object($param)) {
                    $param = $param->getChannel() . '/' . $param->getPackage();
                }
                $this->pushError('Package "' . $param . '" is not valid',
                    PEAR_INSTALLER_SKIPPED);
            }
        }
        PEAR_Downloader_Package::removeDuplicates($params);
        if (!isset($this->_options['nodeps'])) {
            foreach ($params as $i => $param) {
                $params[$i]->detectDependencies($params);
            }
        }
        while (PEAR_Downloader_Package::mergeDependencies($params));
        PEAR_Downloader_Package::removeInstalled($params);
        if (!count($params)) {
            $this->pushError('No valid packages found', PEAR_INSTALLER_FAILED);
        }
        PEAR::pushErrorHandling(PEAR_ERROR_RETURN);
        $err = PEAR_Downloader_Package::analyzeDependencies($params);
        PEAR::popErrorHandling();
        if (PEAR::isError($err)) {
            $this->pushError($err->getMessage());
            return;
        }
        $ret = array();
        $newparams = array();
        foreach ($params as $package) {
            PEAR::staticPushErrorHandling(PEAR_ERROR_RETURN);
            $pf = &$package->download();
            PEAR::staticPopErrorHandling();
            if (PEAR::isError($pf)) {
                $this->log(1, $pf->getMessage());
                $this->log(0, 'Error: cannot download "' .
                    $this->_registry->parsedPackageNameToString($package->getParsedPackage()) .
                    '"');
                continue;
            }
            $newparams[] = &$package;
            $ret[] = array('file' => $pf->getArchiveFile(),
                                   'info' => &$pf,
                                   'pkg' => $pf->getPackage());
        }
        $this->_downloadedPackages = $ret;
        return $newparams;
    }

    /**
     * Retrieve the directory that downloads will happen in
     * @access private
     * @return string
     */
    function getDownloadDir()
    {
        if (isset($this->_downloadDir)) {
            return $this->_downloadDir;
        }
        $downloaddir = $this->config->get('download_dir');
        if (empty($downloaddir)) {
            if (PEAR::isError($downloaddir = System::mktemp('-d'))) {
                return $downloaddir;
            }
            $this->log(3, '+ tmp dir created at ' . $downloaddir);
        }
        return $this->_downloadDir = $downloaddir;
    }

    // }}}
    // {{{ configSet()
    function configSet($key, $value, $layer = 'user', $channel = false)
    {
        $this->config->set($key, $value, $layer, $channel);
        $this->_preferredState = $this->config->get('preferred_state', null, $channel);
        if (!$this->_preferredState) {
            // don't inadvertantly use a non-set preferred_state
            $this->_preferredState = null;
        }
    }

    // }}}
    // {{{ setOptions()
    function setOptions($options)
    {
        $this->_options = $options;
    }

    // }}}
    // {{{ setOptions()
    function getOptions()
    {
        return $this->_options;
    }

    // }}}
    // {{{ _getPackageDownloadUrl()

    /**
     * @param array output of {@link parsePackageName()}
     * @access private
     */
    function _getPackageDownloadUrl($parr)
    {
        $curchannel = $this->config->get('default_channel');
        $this->configSet('default_channel', $parr['channel']);
        // getDownloadURL returns an array.  On error, it only contains information
        // on the latest release as array(version, info).  On success it contains
        // array(version, info, download url string)
        $url = $this->_remote->call('package.getDownloadURL', $parr,
            $this->config->get('preferred_state'));
        if ($parr['channel'] != $curchannel) {
            $this->configSet('default_channel', $curchannel);
        }
        if (isset($url['__PEAR_ERROR_CLASS__'])) {
            return PEAR::raiseError($url['message']);
        }
        if (!extension_loaded("zlib")) {
            $ext = '.tar';
        } else {
            $ext = '.tgz';
        }
        if (is_array($url)) {
            if (isset($url['url'])) {
                $url['url'] .= $ext;
            }
        }
        return $url;
    }
    // }}}
    // {{{ getDepPackageDownloadUrl()

    /**
     * @param array dependency array
     * @access private
     */
    function _getDepPackageDownloadUrl($dep, $parr)
    {
        $xsdversion = isset($dep['rel']) ? '1.0' : '2.0';
        $curchannel = $this->config->get('default_channel');
        $this->configSet('default_channel', $parr['channel']);
        $url = $this->_remote->call('package.getDepDownloadURL', $xsdversion, $dep,
            $parr, $this->config->get('preferred_state'));
        if ($parr['channel'] != $curchannel) {
            $this->configSet('default_channel', $curchannel);
        }
        if (is_array($url)) {
            if (!extension_loaded("zlib")) {
                $ext = '.tar';
            } else {
                $ext = '.tgz';
            }
            if (count($url) == 3) {
                $url['url'] .= $ext;
            }
        }
        return $url;
    }
    // }}}
    // {{{ getPackageDownloadUrl()

    /**
     * @deprecated in favor of _getPackageDownloadUrl
     */
    function getPackageDownloadUrl($package, $version = null, $channel = false)
    {
        if ($version) {
            $package .= "-$version";
        }
        if ($this === null || $this->_registry === null) {
            $package = "http://pear.php.net/get/$package";
        } else {
            $chan = $this->_registry->getChannel();
            $package = "http://" . $chan->getServer() . "/get/$package";
        }
        if (!extension_loaded("zlib")) {
            $package .= '?uncompress=yes';
        }
        return $package;
    }

    // }}}
    // {{{ extractDownloadFileName($pkgfile, &$version)

    function extractDownloadFileName($pkgfile, &$version)
    {
        if (!isset($this->_registry)) {
            $this->_registry = &new PEAR_Registry($this->config->get('php_dir'));
        }
        if (@is_file($pkgfile)) {
            return $pkgfile;
        }
        PEAR::pushErrorHandling(PEAR_ERROR_RETURN);
        $parsed = $this->_registry->parsePackageName($pkgfile);
        PEAR::popErrorHandling();
        if (!$parsed) {
            // this is a url
            return $pkgfile;
        }
        $package = $parsed['package'];
        
        $chan = $this->_registry->getChannel($channel);
        if (!$chan) {
            // regexes defined in Common.php
            if (preg_match(PEAR_COMMON_CHANNEL_DOWNLOAD_PREG, $pkgfile, $m)) {
                $version = (isset($m[4])) ? $m[4] : null;
                return array('channel' => $m[1], 'package' => $m[2]);
            }
            if (preg_match(PEAR_COMMON_PACKAGE_DOWNLOAD_PREG, $pkgfile, $m)) {
                $version = (isset($m[3])) ? $m[3] : null;
                return $m[1];
            }
        } else {
            if (preg_match('/^' . $chan->getChannelPackageDownloadRegex() . '$/', $pkgfile, $m)) {
                $version = (isset($m[4])) ? $m[4] : null;
                return array('channel' => $m[1], 'package' => $m[2]);
            }
            if (preg_match('/^' . $chan->getPackageDownloadRegex() . '$/', $pkgfile, $m)) {
                $version = (isset($m[3])) ? $m[3] : null;
                return $m[1];
            }
        }
        $version = null;
        return $pkgfile;
    }

    // }}}

    // }}}
    // {{{ getDownloadedPackages()

    /**
     * Retrieve a list of downloaded packages after a call to {@link download()}.
     *
     * Also resets the list of downloaded packages.
     * @return array
     */
    function getDownloadedPackages()
    {
        $ret = $this->_downloadedPackages;
        $this->_downloadedPackages = array();
        $this->_toDownload = array();
        return $ret;
    }

    // }}}
    // {{{ _downloadCallback()

    function _downloadCallback($msg, $params = null)
    {
        switch ($msg) {
            case 'saveas':
                $this->log(1, "downloading $params ...");
                break;
            case 'done':
                $this->log(1, '...done: ' . number_format($params, 0, '', ',') . ' bytes');
                break;
            case 'bytesread':
                static $bytes;
                if (empty($bytes)) {
                    $bytes = 0;
                }
                if (!($bytes % 10240)) {
                    $this->log(1, '.', false);
                }
                $bytes += $params;
                break;
            case 'start':
                $this->log(1, "Starting to download {$params[0]} (".number_format($params[1], 0, '', ',')." bytes)");
                break;
        }
        if (method_exists($this->ui, '_downloadCallback'))
            $this->ui->_downloadCallback($msg, $params);
    }

    // }}}
    // {{{ _prependPath($path, $prepend)

    function _prependPath($path, $prepend)
    {
        if (strlen($prepend) > 0) {
            if (OS_WINDOWS && preg_match('/^[a-z]:/i', $path)) {
                $path = $prepend . substr($path, 2);
            } else {
                $path = $prepend . $path;
            }
        }
        return $path;
    }
    // }}}
    // {{{ pushError($errmsg, $code)

    /**
     * @param string
     * @param integer
     */
    function pushError($errmsg, $code = -1)
    {
        array_push($this->_errorStack, array($errmsg, $code));
    }

    // }}}
    // {{{ getErrorMsgs()

    function getErrorMsgs()
    {
        $msgs = array();
        $errs = $this->_errorStack;
        foreach ($errs as $err) {
            $msgs[] = $err[0];
        }
        $this->_errorStack = array();
        return $msgs;
    }

    // }}}

    /**
     * for BC
     */
    function sortPkgDeps(&$packages, $uninstall = false)
    {
        $uninstall ? 
            $this->sortPackagesForUninstall($packages) :
            $this->sortPackagesForInstall($packages);
    }

    function _getDepTreeDP($package, $packages, &$deps, &$checked)
    {
        $pf = $package->getPackageFile();
        $checked[strtolower($package->getChannel())][strtolower($package->getPackage())]
            = true;
        $pdeps = $pf->getDeps(true);
        if (!$pdeps) {
            return;
        }
        if ($pf->getPackagexmlVersion() == '1.0') {
            foreach ($pdeps as $dep) {
                if ($dep['type'] != 'pkg') {
                    continue;
                }
                $deps['pear.php.net'][strtolower($dep['name'])] = true;
                foreach ($packages as $p) {
                    $dep['channel'] = 'pear.php.net';
                    $dep['package'] = $dep['name'];
                    unset($dep['version']);
                    if ($p->isEqual($dep)) {
                        if (!isset($checked[strtolower($p->getChannel())]
                              [strtolower($p->getPackage())])) {
                            // add the dependency's dependencies to the tree
                            $this->_getDepTreeDP($p, $packages, $deps, $checked);
                        }
                    }
                }
            }
        } else {
            $tdeps = array();
            if (isset($pdeps['required']['package'])) {
                $t = $pdeps['required']['package'];
                if (!isset($t[0])) {
                    $t = array($t);
                }
                $tdeps = array_merge($tdeps, $t);
            }
            if (isset($pdeps['required']['subpackage'])) {
                $t = $pdeps['required']['subpackage'];
                if (!isset($t[0])) {
                    $t = array($t);
                }
                $tdeps = array_merge($tdeps, $t);
            }
            if (isset($pdeps['optional']['package'])) {
                $t = $pdeps['optional']['package'];
                if (!isset($t[0])) {
                    $t = array($t);
                }
                $tdeps = array_merge($tdeps, $t);
            }
            if (isset($pdeps['optional']['subpackage'])) {
                $t = $pdeps['optional']['subpackage'];
                if (!isset($t[0])) {
                    $t = array($t);
                }
                $tdeps = array_merge($tdeps, $t);
            }
            if (isset($pdeps['group'])) {
                if (!isset($pdeps['group'][0])) {
                    $pdeps['group'] = array($pdeps['group']);
                }
                foreach ($pdeps['group'] as $group) {
                    if (isset($group['package'])) {
                        $t = $group['package'];
                        if (!isset($t[0])) {
                            $t = array($t);
                        }
                        $tdeps = array_merge($tdeps, $t);
                    }
                    if (isset($group['subpackage'])) {
                        $t = $group['subpackage'];
                        if (!isset($t[0])) {
                            $t = array($t);
                        }
                        $tdeps = array_merge($tdeps, $t);
                    }
                }
            }
            foreach ($tdeps as $dep) {
                if (!isset($dep['channel'])) {
                    $depchannel = '__private';
                } else {
                    $depchannel = $dep['channel'];
                }
                $deps[$depchannel][strtolower($dep['name'])] = true;
                foreach ($packages as $p) {
                    $dep['channel'] = $depchannel;
                    $dep['package'] = $dep['name'];
                    if ($p->isEqual($dep)) {
                        if (!isset($checked[strtolower($p->getChannel())]
                              [strtolower($p->getPackage())])) {
                            // add the dependency's dependencies to the tree
                            $this->_getDepTreeDP($p, $packages, $deps, $checked);
                        }
                    }
                }
            }
        }
    }

    /**
     * Sort a list of arrays of array(downloaded packagefilename) by dependency.
     *
     * It also removes duplicate dependencies
     * @param array an array of downloaded PEAR_Downloader_Packages
     * @return array array of array(packagefilename, package.xml contents)
     */
    function sortPackagesForInstall(&$packages)
    {
        foreach ($packages as $i => $package) {
            $checked = $deps = array();
            $this->_getDepTreeDP($packages[$i], $packages, $deps, $checked);
            $this->_depTree[$package->getChannel()][$package->getPackage()] = $deps;
        }
        usort($packages, array(&$this, '_sortInstall'));
    }

    function _dependsOn($a, $b)
    {
        return $this->_checkDepTree(strtolower($a->getChannel()), strtolower($a->getPackage()),
            $b);
    }

    function _checkDepTree($channel, $package, $b, $checked = array())
    {
        $checked[$channel][$package] = true;
        if (!isset($this->_depTree[$channel][$package])) {
            return false;
        }
        if (isset($this->_depTree[$channel][$package][strtolower($b->getChannel())]
              [strtolower($b->getPackage())])) {
            return true;
        }
        foreach ($this->_depTree[$channel][$package] as $ch => $packages) {
            foreach ($packages as $pa => $true) {
                if ($this->_checkDepTree($ch, $pa, $b, $checked)) {
                    return true;
                }
            }
        }
        return false;
    }

    function _sortInstall($a, $b)
    {
        if (!$a->getDeps() && !$b->getDeps()) {
            return 0; // neither package has dependencies, order is insignificant
        }
        if ($a->getDeps() && !$b->getDeps()) {
            return 1; // $a must be installed after $b because $a has dependencies
        }
        if (!$a->getDeps() && $b->getDeps()) {
            return -1; // $b must be installed after $a because $b has dependencies
        }
        // both packages have dependencies
        if ($this->_dependsOn($a, $b)) {
            return 1;
        }
        if ($this->_dependsOn($b, $a)) {
            return -1;
        }
        return 0;
    }

    /**
     * Sort a list of arrays of array(downloaded packagefilename) by dependency.
     *
     * It also removes duplicate dependencies
     * @param array an array of PEAR_PackageFile_v[1/2] objects
     * @return array array of array(packagefilename, package.xml contents)
     */
    function sortPackagesForUninstall(&$packages)
    {
    }

    function _uninstallDependsOn($a, $b)
    {
    }

    function _sortUninstall($a, $b)
    {
        if (!$a->getDeps() && !$b->getDeps()) {
            return 0; // neither package has dependencies, order is insignificant
        }
        if ($a->getDeps() && !$b->getDeps()) {
            return 1; // $a must be installed after $b because $a has dependencies
        }
        if (!$a->getDeps() && $b->getDeps()) {
            return -1; // $b must be installed after $a because $b has dependencies
        }
        // both packages have dependencies
        if ($this->_dependsOn($a, $b)) {
            return 1;
        }
        if ($this->_dependsOn($b, $a)) {
            return -1;
        }
        return 0;
    }

    /**
     * Download a file through HTTP.  Considers suggested file name in
     * Content-disposition: header and can run a callback function for
     * different events.  The callback will be called with two
     * parameters: the callback type, and parameters.  The implemented
     * callback types are:
     *
     *  'setup'       called at the very beginning, parameter is a UI object
     *                that should be used for all output
     *  'message'     the parameter is a string with an informational message
     *  'saveas'      may be used to save with a different file name, the
     *                parameter is the filename that is about to be used.
     *                If a 'saveas' callback returns a non-empty string,
     *                that file name will be used as the filename instead.
     *                Note that $save_dir will not be affected by this, only
     *                the basename of the file.
     *  'start'       download is starting, parameter is number of bytes
     *                that are expected, or -1 if unknown
     *  'bytesread'   parameter is the number of bytes read so far
     *  'done'        download is complete, parameter is the total number
     *                of bytes read
     *  'connfailed'  if the TCP connection fails, this callback is called
     *                with array(host,port,errno,errmsg)
     *  'writefailed' if writing to disk fails, this callback is called
     *                with array(destfile,errmsg)
     *
     * If an HTTP proxy has been configured (http_proxy PEAR_Config
     * setting), the proxy will be used.
     *
     * @param string  $url       the URL to download
     * @param object  $ui        PEAR_Frontend_* instance
     * @param object  $config    PEAR_Config instance
     * @param string  $save_dir  (optional) directory to save file in
     * @param mixed   $callback  (optional) function/method to call for status
     *                           updates
     *
     * @return string  Returns the full path of the downloaded file or a PEAR
     *                 error on failure.  If the error is caused by
     *                 socket-related errors, the error object will
     *                 have the fsockopen error code available through
     *                 getCode().
     *
     * @access public
     */
    function downloadHttp($url, &$ui, $save_dir = '.', $callback = null)
    {
        if ($callback) {
            call_user_func($callback, 'setup', array(&$ui));
        }
        $info = parse_url($url);
        if (!isset($info['scheme']) || $info['scheme'] != 'http') {
            return PEAR::raiseError('Cannot download non-http URL "' . $url . '"');
        }
        if (!isset($info['host'])) {
            return PEAR::raiseError('Cannot download from non-URL "' . $url . '"');
        } else {
            $host = @$info['host'];
            $port = @$info['port'];
            $path = @$info['path'];
        }
        if (isset($this)) {
            $config = &$this->config;
        } else {
            $config = &PEAR_Config::singleton();
        }
        $proxy_host = $proxy_port = $proxy_user = $proxy_pass = '';
        if ($config->get('http_proxy')&& 
              $proxy = parse_url($config->get('http_proxy'))) {
            $proxy_host = @$proxy['host'];
            $proxy_port = @$proxy['port'];
            $proxy_user = @$proxy['user'];
            $proxy_pass = @$proxy['pass'];

            if ($proxy_port == '') {
                $proxy_port = 8080;
            }
            if ($callback) {
                call_user_func($callback, 'message', "Using HTTP proxy $host:$port");
            }
        }
        if (empty($port)) {
            $port = 80;
        }
        if ($proxy_host != '') {
            $fp = @fsockopen($proxy_host, $proxy_port, $errno, $errstr);
            if (!$fp) {
                if ($callback) {
                    call_user_func($callback, 'connfailed', array($proxy_host, $proxy_port,
                                                                  $errno, $errstr));
                }
                return PEAR::raiseError("Connection to `$proxy_host:$proxy_port' failed: $errstr", $errno);
            }
            $request = "GET $url HTTP/1.0\r\n";
        } else {
            $fp = @fsockopen($host, $port, $errno, $errstr);
            if (!$fp) {
                if ($callback) {
                    call_user_func($callback, 'connfailed', array($host, $port,
                                                                  $errno, $errstr));
                }
                return PEAR::raiseError("Connection to `$host:$port' failed: $errstr", $errno);
            }
            $request = "GET $path HTTP/1.0\r\n";
        }
        $request .= "Host: $host:$port\r\n".
            "User-Agent: PHP/".PHP_VERSION."\r\n";
        if ($proxy_host != '' && $proxy_user != '') {
            $request .= 'Proxy-Authorization: Basic ' .
                base64_encode($proxy_user . ':' . $proxy_pass) . "\r\n";
        }
        $request .= "\r\n";
        fwrite($fp, $request);
        $headers = array();
        while (trim($line = fgets($fp, 1024))) {
            if (preg_match('/^([^:]+):\s+(.*)\s*$/', $line, $matches)) {
                $headers[strtolower($matches[1])] = trim($matches[2]);
            } elseif (preg_match('|^HTTP/1.[01] ([0-9]{3}) |', $line, $matches)) {
                if ($matches[1] != 200) {
                    return PEAR::raiseError("File http://$host:$port$path not valid (received: $line)");
                }
            }
        }
        if (isset($headers['content-disposition']) &&
            preg_match('/\sfilename=\"([^;]*\S)\"\s*(;|$)/', $headers['content-disposition'], $matches)) {
            $save_as = basename($matches[1]);
        } else {
            $save_as = basename($url);
        }
        if ($callback) {
            $tmp = call_user_func($callback, 'saveas', $save_as);
            if ($tmp) {
                $save_as = $tmp;
            }
        }
        $dest_file = $save_dir . DIRECTORY_SEPARATOR . $save_as;
        if (!$wp = @fopen($dest_file, 'wb')) {
            fclose($fp);
            if ($callback) {
                call_user_func($callback, 'writefailed', array($dest_file, $php_errormsg));
            }
            return PEAR::raiseError("could not open $dest_file for writing");
        }
        if (isset($headers['content-length'])) {
            $length = $headers['content-length'];
        } else {
            $length = -1;
        }
        $bytes = 0;
        if ($callback) {
            call_user_func($callback, 'start', array(basename($dest_file), $length));
        }
        while ($data = @fread($fp, 1024)) {
            $bytes += strlen($data);
            if ($callback) {
                call_user_func($callback, 'bytesread', $bytes);
            }
            if (!@fwrite($wp, $data)) {
                fclose($fp);
                if ($callback) {
                    call_user_func($callback, 'writefailed', array($dest_file, $php_errormsg));
                }
                return PEAR::raiseError("$dest_file: write failed ($php_errormsg)");
            }
        }
        fclose($fp);
        fclose($wp);
        if ($callback) {
            call_user_func($callback, 'done', $bytes);
        }
        return $dest_file;
    }
}
// }}}

?>