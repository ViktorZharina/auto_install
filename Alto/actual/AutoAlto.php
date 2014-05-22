<?php
set_time_limit(0);

class AutoAlto
{
    
    const LOCAL_CONFIG_FILE_NAME = 'config.local.php';
    
    public $sTemplatesDir = 'templates';
    public $sLangInstallDir = 'language';
    public $aValidEnv = array(
        'safe_mode' => array(
            '0',
            'off',
            ''
        ) ,
        'register_globals' => array(
            '0',
            'off',
            ''
        ) ,
        'allow_url_fopen' => array(
            '1',
            'on'
        ) ,
        'UTF8_support' => '1',
        'http_input' => array(
            '',
            'pass'
        ) ,
        'http_output' => array(
            '0',
            'pass'
        ) ,
        'func_overload' => array(
            '0',
            '4',
            'no overload'
        ) ,
    );
    
    private $aParams = array();
    
    public function install($params) {
        $this->aParams = $params;
        $this->ValidateStep();
        $this->SaveConfigStep();
        $this->DbStep();
        
        return array(
            'title' => 'Реквизиты',
            'login' => $this->aParams['login'],
            'password' => $this->aParams['password'],
            'url' => $this->aParams['root_web'],
            'to_email' => $this->aParams['to_email']
        );
    }
    /**
     * Проверяем возможность инсталяции
     *
     * @throw exception
     */
    private function ValidateStep() {
        
        if (!version_compare(PHP_VERSION, '5.3.0', '>=')) {
            throw new Exception('Старая версия PHP. Обновитесь хотя бы до 5.3.0');
        }
        
        if (!in_array(strtolower(@ini_get('safe_mode')) , $this->aValidEnv['safe_mode'])) {
            throw new Exception('Включите safe-mode в настройках php.ini');
        }
        
        if (@preg_match('//u', '') != $this->aValidEnv['UTF8_support']) {
            throw new Exception('UTF8 support no');
        }
        
        if (!@extension_loaded('mbstring')) {
            throw new Exception('mb string not loaded');
        }
        
        if (!@extension_loaded('SimpleXML')) {
            throw new Exception('SimpleXML');
        }
        
        $sLocalConfigPath = $this->aParams['config_dir'] . '/config.local.php';
        if (!file_exists($sLocalConfigPath) or !is_writeable($sLocalConfigPath)) {
            // пытаемся создать файл локального конфига
            @copy($this->aParams['config_dir'] . '/config.local.php.txt', $sLocalConfigPath);
        }
        
        if (!file_exists($sLocalConfigPath) or !is_writeable($sLocalConfigPath)) {
            
            throw new Exception('config.local.php');
        }
        
        $sTempDir = $this->aParams['path_to_account'] . '_tmp';
        
        if (!is_dir($sTempDir) or !is_writable($sTempDir)) {
            throw new Exception('validate_local_temp_dir');
        }
        
        $sLogsDir = $this->aParams['path_to_account'] . '_run';
        
        if (!is_dir($sLogsDir) or !is_writable($sLogsDir)) {
            throw new Exception('validate_local_logs');
        }
        
        $sPluginsDir = $this->aParams['path_to_account'] . 'app/plugins';
        if (!is_dir($sPluginsDir) or !is_writable($sPluginsDir)) {
            throw new Exception('validate_local_plugins');
        }
        
        return TRUE;
    }
    /**
     * Сохраняет в конфигурации абсолютные пути
     *
     * @access public
     * @return null
     */
    private function SaveConfigStep() {
        $sLocalConfigFile = $this->aParams['config_dir'] . '/' . self::LOCAL_CONFIG_FILE_NAME;
        
        if (!file_exists($sLocalConfigFile)) {
            throw new Exception('error_local_config_invalid');
        }
        
        $this->SaveConfig('path.root.web', $this->aParams['root_web'], $sLocalConfigFile);
        $this->SaveConfig('path.root.url', $this->aParams['root_web'], $sLocalConfigFile);
        $this->SaveConfig('path.root.server', $this->aParams['path_to_account'], $sLocalConfigFile);
        $this->SaveConfig('path.offset_request_url', 2, $sLocalConfigFile);
        $this->SaveConfig('module.security.hash', $this->aParams['password'] . rand(1, 100) , $sLocalConfigFile);
        $this->SaveConfig('db.params.host', $this->aParams['db_server'], $sLocalConfigFile);
        $this->SaveConfig('db.params.port', $this->aParams['db_port'], $sLocalConfigFile);
        $this->SaveConfig('db.params.user', $this->aParams['db_user'], $sLocalConfigFile);
        $this->SaveConfig('db.params.pass', $this->aParams['db_password'], $sLocalConfigFile);
        $this->SaveConfig('db.params.dbname', $this->aParams['db_name'], $sLocalConfigFile);
        $this->SaveConfig('db.table.prefix', $this->aParams['db_prefix'], $sLocalConfigFile);
        $this->SaveConfig('db.tables.engine', $this->aParams['db_engine'], $sLocalConfigFile);
        $this->SaveConfig('security.salt_sess', $this->RandomStr(64, false) , $sLocalConfigFile);
        $this->SaveConfig('security.salt_pass', $this->RandomStr(64, false) , $sLocalConfigFile);
        $this->SaveConfig('security.salt_auth', $this->RandomStr(64, false) , $sLocalConfigFile);
        $this->SaveConfig('general.close', true, $sLocalConfigFile);
        $this->SaveConfig('general.reg.invite', true, $sLocalConfigFile);
        return TRUE;
    }
    
    private function DbStep() {
        try {
            $pdo = new PDO("mysql:host={$this->aParams['db_server']}", $this->aParams['db_root_user'], $this->aParams['db_root_password'], array(
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ));
            
            $pdo->query("CREATE DATABASE IF NOT EXISTS `{$this->aParams['db_name']}`");
            $pdo->exec("GRANT USAGE ON *.* TO `{$this->aParams['db_user']}`@`{$this->aParams['db_server']}` identified by '{$this->aParams['db_password']}'");
            $pdo->exec("GRANT ALL privileges on `{$this->aParams['db_name']}`.* to `{$this->aParams['db_user']}`@`{$this->aParams['db_server']}`");
            $pdo = null;
            
            $pdo = new PDO("mysql:host={$this->aParams['db_server']};dbname={$this->aParams['db_name']}", $this->aParams['db_user'], $this->aParams['db_password'], array(
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ));
            $pdo->exec("SET NAMES utf8");
            $aQuery = $this->_loadQueries(__DIR__ . '/db/' . 'geo_base.sql');
            
            foreach ($aQuery as $sQuery) {
                $sQuery = trim($sQuery);
                
                if ($sQuery != '') {
                    $pdo->query($sQuery);
                }
            }
            
            $aQuery = $this->_loadQueries(__DIR__ . '/db/' . 'sql.sql');
            
            foreach ($aQuery as $sQuery) {
                $sQuery = trim($sQuery);
                
                if ($sQuery != '') {
                    $pdo->query($sQuery);
                }
            }
            
            $UpdateUserQuery = "UPDATE {$this->aParams['db_prefix']}user SET user_login    = '{$this->aParams['login']}',
            user_mail     = '{$this->aParams['email']}', 
            user_password = md5('{$this->aParams['password']}') WHERE user_id = 1";
            $pdo->query($UpdateUserQuery);
            
            $pdo->query(" UPDATE {$this->aParams['db_prefix']}blog SET blog_title = 'Blog by admin' WHERE blog_id = 1");
        }
        catch(PDOException $e) {
            throw new Exception('Message: ' . $e->getMessage() . ' LINE: ' . $e->getLine());
        }
        return TRUE;
    }
    /**
     * Сохранить данные в конфиг-файл
     *
     * @param  string $sName
     * @param  string $sVar
     * @param  string $sPath
     * @return bool
     */
    private function SaveConfig($sName, $sVar, $sPath) {
        if (!file_exists($sPath)) {
            throw new Exception('config_file_not_exists ' . $sPath);
        }
        
        if (!is_writeable($sPath)) {
            throw new Exception('config_file_not_writable" ' . $sPath);
        }
        
        $sConfig = file_get_contents($sPath);
        $sName = '$config[\'' . implode('\'][\'', explode('.', $sName)) . '\']';
        $sVar = $this->ConvertToString($sVar);
        /**
         * Если переменная уже определена в конфиге,
         * то меняем значение.
         */
        if (substr_count($sConfig, $sName)) {
            $sConfig = preg_replace("~" . preg_quote($sName) . ".+;~Ui", $sName . ' = ' . $sVar . ';', $sConfig);
        } else {
            $sConfig = str_replace('return $config;', $sName . ' = ' . $sVar . ';' . PHP_EOL . 'return $config;', $sConfig);
        }
        
        return file_put_contents($sPath, $sConfig);
    }
    /**
     * Преобразует переменную в формат для записи в текстовый файл
     *
     * @param  mixed $mVar
     * @return string
     */
    private function ConvertToString($mVar) {
        switch (true) {
            case is_string($mVar):
                return "'" . addslashes($mVar) . "'";
            case is_bool($mVar):
                return ($mVar) ? "true" : "false";
            case is_array($mVar):
                $sArrayString = "";
                foreach ($mVar as $sKey => $sValue) {
                    $sArrayString.= "'{$sKey}'=>" . $this->ConvertToString($sValue) . ",";
                }
                return "array(" . $sArrayString . ")";
            default:
            case is_numeric($mVar):
                return "'" . (string)$mVar . "'";
        }
    }
    
    static protected $sRandChars = '0123456789_ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
    /**
     * Возвращает строку со случайным набором символов
     *
     * @param   int     $nLen   - длина строка
     * @param   bool    $bHex   - только шестнадцатиричные символы [0-9a-f]
     *
     * @return  string
     */
    private function RandomStr($nLen = 32, $bHex = true) {
        $sResult = '';
        if ($bHex) {
            while (strlen($sResult) < $nLen) {
                $sResult.= md5(uniqid(md5(rand()) , true));
            }
            if (strlen($sResult) > $nLen) {
                $sResult = substr($sResult, 0, $nLen);
            }
        } else {
            $nMax = strlen(self::$sRandChars) - 1;
            while (strlen($sResult) < $nLen) {
                $sResult.= self::$sRandChars[rand(0, $nMax) ];
            }
        }
        return $sResult;
    }
    
    protected function _loadQueries($sFile) {
        $aQueries = array();
        $nCnt = 0;
        $sQuery = '';
        
        if (!$aLines = @file($sFile)) {
            throw new Exception('sql file not found');
        }
        
        foreach ($aLines as $sStr) {
            if (isset($this->aParams['db_prefix'])) {
                $sStr = str_replace('prefix_', $this->aParams['db_prefix'], $sStr);
            }

            if (substr(trim($sStr) , -1) == ';') {
                $sQuery.= $sStr;
                $aQueries[$nCnt++] = $sQuery;
                $sQuery = '';
            } else {
                $sQuery.= $sStr;
            }
        }
        return $aQueries;
    }
}
