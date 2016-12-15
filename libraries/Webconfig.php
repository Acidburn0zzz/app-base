<?php

/**
 * Webconfig class.
 *
 * @category   apps
 * @package    base
 * @subpackage libraries
 * @author     ClearFoundation <developer@clearfoundation.com>
 * @copyright  2006-2016 ClearFoundation
 * @license    http://www.gnu.org/copyleft/lgpl.html GNU Lesser General Public License version 3 or later
 * @link       http://www.clearfoundation.com/docs/developer/apps/base/
 */

///////////////////////////////////////////////////////////////////////////////
//
// This program is free software: you can redistribute it and/or modify
// it under the terms of the GNU Lesser General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// This program is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU Lesser General Public License for more details.
//
// You should have received a copy of the GNU Lesser General Public License
// along with this program.  If not, see <http://www.gnu.org/licenses/>.
//
///////////////////////////////////////////////////////////////////////////////

///////////////////////////////////////////////////////////////////////////////
// N A M E S P A C E
///////////////////////////////////////////////////////////////////////////////

namespace clearos\apps\base;

///////////////////////////////////////////////////////////////////////////////
// B O O T S T R A P
///////////////////////////////////////////////////////////////////////////////

$bootstrap = getenv('CLEAROS_BOOTSTRAP') ? getenv('CLEAROS_BOOTSTRAP') : '/usr/clearos/framework/shared';
require_once $bootstrap . '/bootstrap.php';

///////////////////////////////////////////////////////////////////////////////
// T R A N S L A T I O N S
///////////////////////////////////////////////////////////////////////////////

clearos_load_language('base');

///////////////////////////////////////////////////////////////////////////////
// D E P E N D E N C I E S
///////////////////////////////////////////////////////////////////////////////

// Classes
//--------

use \clearos\apps\base\Configuration_File as Configuration_File;
use \clearos\apps\base\Daemon as Daemon;
use \clearos\apps\base\File as File;
use \clearos\apps\base\Folder as Folder;
use \clearos\apps\base\Posix_User as Posix_User;
use \clearos\apps\base\Webconfig as Webconfig;
use \clearos\apps\certificate_manager\Certificate_Manager as Certificate_Manager;

clearos_load_library('base/Configuration_File');
clearos_load_library('base/Daemon');
clearos_load_library('base/File');
clearos_load_library('base/Folder');
clearos_load_library('base/Posix_User');
clearos_load_library('base/Webconfig');
clearos_load_library('certificate_manager/Certificate_Manager');

// Exceptions
//-----------

use \clearos\apps\base\Engine_Exception as Engine_Exception;
use \clearos\apps\base\File_No_Match_Exception as File_No_Match_Exception;
use \clearos\apps\base\File_Not_Found_Exception as File_Not_Found_Exception;

clearos_load_library('base/Engine_Exception');
clearos_load_library('base/File_No_Match_Exception');
clearos_load_library('base/File_Not_Found_Exception');

///////////////////////////////////////////////////////////////////////////////
// C L A S S
///////////////////////////////////////////////////////////////////////////////

/**
 * Webconfig class.
 *
 * Only application-level methods are in this class.  In other words, no
 * GUI components are found here.
 *
 * @category   apps
 * @package    base
 * @subpackage libraries
 * @author     ClearFoundation <developer@clearfoundation.com>
 * @copyright  2006-2011 ClearFoundation
 * @license    http://www.gnu.org/copyleft/lgpl.html GNU Lesser General Public License version 3 or later
 * @link       http://www.clearfoundation.com/docs/developer/apps/base/
 */

class Webconfig extends Daemon
{
    ///////////////////////////////////////////////////////////////////////////////
    // C O N S T A N T S
    ///////////////////////////////////////////////////////////////////////////////

    const CMD_VALIDATE_HTTPD = '/usr/clearos/sandbox/usr/sbin/httpd';
    const FILE_CONFIG = '/etc/clearos/webconfig.conf';
    const FILE_RESTART = '/var/clearos/base/webconfig_restart';
    const FILE_FRAMEWORK_CONF = '/usr/clearos/sandbox/etc/httpd/conf.d/framework.conf';
    const FILE_DEFAULT_SSL_CRT = '/usr/clearos/sandbox/etc/httpd/conf/server.crt';
    const FILE_DEFAULT_SSL_KEY = '/usr/clearos/sandbox/etc/httpd/conf/server.key';

    ///////////////////////////////////////////////////////////////////////////////
    // V A R I A B L E S
    ///////////////////////////////////////////////////////////////////////////////

    protected $is_loaded = FALSE;
    protected $config = array();

    ///////////////////////////////////////////////////////////////////////////////
    // M E T H O D S
    ///////////////////////////////////////////////////////////////////////////////

    /**
     * Webconfig constructor.
     */

    public function __construct()
    {
        clearos_profile(__METHOD__, __LINE__);

        parent::__construct("webconfig-httpd");
    }

    /**
     * Returns configured theme.
     *
     * @return string theme
     * @throws Engine_Exception
     */

    public function get_theme()
    {
        clearos_profile(__METHOD__, __LINE__);

        if (! $this->is_loaded)
            $this->_load_config();

        return $this->config['theme'];
    }

    /**
     * Returns SSL certificate in use.
     *
     * @return string ssl cert
     * @throws Engine_Exception
     */

    public function get_ssl_certificate()
    {
        clearos_profile(__METHOD__, __LINE__);

        $file = new File(self::FILE_FRAMEWORK_CONF, TRUE);
        return $file->lookup_value('/^\s*SSLCertificateFile\s*/');
    }

    /**
     * Returns the list of available themes for webconfig.
     *
     * @return array list of theme names
     * @throws Engine_Exception
     */

    public function get_themes()
    {
        clearos_profile(__METHOD__, __LINE__);

        return clearos_get_themes();
    }

    /**
     * Returns configured theme options.
     *
     * @return array theme options
     * @throws Engine_Exception
     */

    public function get_theme_settings()
    {
        clearos_profile(__METHOD__, __LINE__);

        if (! $this->is_loaded)
            $this->_load_config();

        if (isset($this->config[$this->get_theme()]))
            return unserialize($this->config[$this->get_theme()]);

        return array();
    }

    /**
     * Returns configured theme metadata.
     *
     * @param string $name theme name
     *
     * @return string theme metadata
     * @throws Engine_Exception
     */

    public function get_theme_metadata($name)
    {
        clearos_profile(__METHOD__, __LINE__);

        $themes = clearos_get_themes();

        if (empty($themes[$name]))
            throw new Engine_Exception('Invalid theme', CLEAROS_ERROR);

        return $themes[$name];
    }

    /**
     * Set a theme option.
     *
     * @param string $name    theme name
     * @param string $options options
     *
     * @throws Engine_Exception
     */

    public function set_theme_options($name, $options)
    {
        clearos_profile(__METHOD__, __LINE__);

        $this->_set_parameter($name, serialize($options));
    }

    /**
     * Set a ssl certificate.
     *
     * @param string $cert    cert
     *
     * @throws Engine_Exception
     */

    public function set_ssl_certificate($cert)
    {
        clearos_profile(__METHOD__, __LINE__);

        // See if we even need to do anything
        if ($cert == $this->get_ssl_certificate())
            return FALSE;

        $certificate_manager = new Certificate_Manager();
        $certs = $certificate_manager->get_certificates();

        // Use default if specified certificate no longer exists
        if (!array_key_exists($cert, $this->get_ssl_certificate_options()))
            $cert = Certificate_Manager::DEFAULT_CERT;

        foreach ($certs as $basename => $certificate) {
            if ($certificate['certificate-filename'] == $cert) {
                $cert_files = $certs[$basename];
                break;
            }
        }
        // Make sure we're not dealing with an external cert with just a CSR and Key pair (no cert)
        if (!array_key_exists('certificate-filename', $cert_files) && array_key_exists('key-filename', $cert_files))
            throw new Engine_Exception(lang('base_ssl_certificate') . ' - ' . lang('base_invalid'));

        $file = new File(self::FILE_FRAMEWORK_CONF, TRUE);
        $file->copy_to(self::FILE_FRAMEWORK_CONF . ".new");
        $file = new File(self::FILE_FRAMEWORK_CONF . ".new", TRUE);
        if ($cert == self::FILE_DEFAULT_SSL_CRT) {
            $file->replace_lines('/^\s*SSLCertificateFile\s*/', "    SSLCertificateFile " . self::FILE_DEFAULT_SSL_CRT . "\n");
            $file->replace_lines('/^\s*SSLCertificateKeyFile\s*/', "    SSLCertificateKeyFile " . self::FILE_DEFAULT_SSL_KEY . "\n");
            try {
                $file->delete_lines('/^\s*SSLCertificateChainFile\s*/');
            } catch (File_No_Match_Exception $e) {
            }
            try {
                $file->delete_lines('/^\s*SSLCACertificateFile\s*/');
            } catch (File_No_Match_Exception $e) {
            }
            
        } else {
            $file->replace_lines('/^\s*SSLCertificateFile\s*/', "    SSLCertificateFile " . $cert_files['certificate-filename'] . "\n");
            $file->replace_lines('/^\s*SSLCertificateKeyFile\s*/', "    SSLCertificateKeyFile " . $cert_files['key-filename'] . "\n");
            if (array_key_exists('intermediate-filename', $cert_files)) {
                $count = $file->replace_lines('/^\s*SSLCertificateChainFile\s*/', "    SSLCertificateChainFile " . $cert_files['intermediate-filename'] . "\n");
                if ($count == 0)
                    $file->add_lines_after('    SSLCertificateChainFile ' . $cert_files['intermediate-filename'] . "\n", '/^\s*SSLCertificateKeyFile\s*/', -1);
            } else {
                try {
                    $file->delete_lines('/^\s*SSLCertificateChainFile\s*/');
                } catch (File_No_Match_Exception $e) {
                }
            }
            if (array_key_exists('ca-filename', $cert_files)) {
                $count = $file->replace_lines('/^\s*SSLCACertificateFile\s*/', "    SSLCACertificateFile " . $cert_files['ca-filename'] . "\n");
                if ($count == 0)
                    $file->add_lines_after('    SSLCACertificateFile ' . $cert_files['ca-filename'] . "\n", '/^\s*SSLCertificateKeyFile\s*/', -1);
            } else {
                try {
                    $file->delete_lines('/^\s*SSLCACertificateFile\s*/');
                } catch (File_No_Match_Exception $e) {
                }
            }
        }
        $config_ok = TRUE;

        try {
            $shell = new Shell();
            $shell_options['validate_exit_code'] = FALSE;
            $exitcode = $shell->execute(self::CMD_VALIDATE_HTTPD, '-t', TRUE, $shell_options);
        } catch (Exception $e) {
            $config_ok = FALSE;
        }

        if (($config_ok === FALSE) || ($exitcode != 0)) {
            $output = $shell->get_output();
            clearos_log(self::LOG_TAG, "Invalid Webconfig httpd configuration!");
            // Oops...we generated an invalid conf file
            foreach ($output as $line)
                clearos_log(self::LOG_TAG, $line);
            $file->delete();
            throw new Engine_Exception(lang('base_ssl_certificate') . ' - ' . lang('base_invalid'));
        }

        $file->move_to(self::FILE_FRAMEWORK_CONF);
        $file->chown('webconfig', 'webconfig');
        $file->chmod(0644);
        return TRUE;
    }

    /**
     * Restarts webconfig in a gentle way.
     *
     * A webconfig restart request through the GUI needs special handling.  
     * To avoid killing itself, a restart is handled by the external
     * clearsync system.
     *
     * @return void
     * @throws Engine_Exception
     */

    public function reset_gently()
    {
        clearos_profile(__METHOD__, __LINE__);

        $file = new File(self::FILE_RESTART);

        if ($file->exists())
            $file->delete();

        $file->create('root', 'root', '0644');
        $file->add_lines("restart requested\n");
    }

    /**
     * Sets the theme for webconfig.
     *
     * @param string $theme theme for webconfig
     *
     * @return void
     * @throws Engine_Exception
     */

    public function set_theme($theme)
    {
        clearos_profile(__METHOD__, __LINE__);

        $this->_set_parameter('theme', $theme);
    }

    /**
     * Gets SSL certificates options.
     *
     * @return array list of valid certificates
     * @throws Engine_Exception
     */

    public function get_ssl_certificate_options()
    {
        clearos_profile(__METHOD__, __LINE__);

        $options = array(
            self::FILE_DEFAULT_SSL_CRT => lang('base_install_default')
        );
        $certificate_manager = new Certificate_Manager();
        $certs = $certificate_manager->get_certificates();
        $certificate_manager = new Certificate_Manager();
        $list = $certificate_manager->get_list();
        // Check at minimum that entries have a key and crt pair
        foreach ($list as $basename => $certificate) {
            if (!array_key_exists('certificate-filename', $certs[$basename]) || !array_key_exists('key-filename', $certs[$basename]))
                continue;
            $options[$certs[$basename]['certificate-filename']] = $certificate;
        }
        return $options;
    }

    ///////////////////////////////////////////////////////////////////////////////
    // P R I V A T E  M E T H O D S
    ///////////////////////////////////////////////////////////////////////////////

    /**
     * Loads configuration files.
     *
     * @return void
     * @throws Engine_Exception
     */

    protected function _load_config()
    {
        clearos_profile(__METHOD__, __LINE__);

        try {
            $config_file = new Configuration_File(self::FILE_CONFIG);
            $this->config = $config_file->load();
        } catch (File_Not_Found_Exception $e) {
            // Not fatal, set defaults below
        } catch (Engine_Exception $e) {
            throw new Engine_Exception($e->get_message(), CLEAROS_WARNING);
        }

        // TODO: remoe hard-coded default theme
        if (!isset($this->config['theme'])) {
            if (clearos_version() == 6)
                $this->config['theme'] = 'default';
            else
                $this->config['theme'] = 'ClearOS-Admin';
        }
 
        $this->is_loaded = TRUE;
    }

    /**
     * Sets a parameter in the config file.
     *
     * @param string $key     name of the key in the config file
     * @param string $value   value for the key
     *
     * @access private
     * @return void
     * @throws Engine_Exception
     */

     protected function _set_parameter($key, $value)
     {
        clearos_profile(__METHOD__, __LINE__);
 
        $file = new File(self::FILE_CONFIG);

        if (! $file->exists())
            $file->create('root', 'root', '0644');
 
        $match = $file->replace_lines("/^$key\s*=\s*/", "$key = $value\n");

        if (!$match)
            $file->add_lines("$key = $value\n");
    }

    ///////////////////////////////////////////////////////////////////////////////
    // V A L I D A T I O N   R O U T I N E S
    ///////////////////////////////////////////////////////////////////////////////

    /**
     * Validation routine for theme options
     *
     * @param string $theme theme
     *
     * @return boolean TRUE if theme is valid
     */

    public function validate_theme_option($theme)
    {
        clearos_profile(__METHOD__, __LINE__);

        if (FALSE)
            return lang('base_parameter_invalid');
    }

    /**
     * Validation webconfig certificate
     *
     * @param string $ssl_certificate ssl certificate
     *
     * @return mixed return error message if cert is invalid
     */

    public function validate_ssl_certificate($ssl_certificate)
    {
        clearos_profile(__METHOD__, __LINE__);

        $options = $this->get_ssl_certificate_options();
    
        if (!array_key_exists($ssl_certificate, $options))
            return lang('base_ssl_certificate') . ' - ' . lang('base_invalid');
    }
}
