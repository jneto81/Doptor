<?php namespace Services;

/*
=================================================
CMS Name  :  DOPTOR
CMS Version :  v1.2
Available at :  www.doptor.org
Copyright : Copyright (coffee) 2011 - 2015 Doptor. All rights reserved.
License : GNU/GPL, visit LICENSE.txt
Description :  Doptor is Opensource CMS.
===================================================
*/
use Artisan;
use DB;
use Exception;
use File;
use Input;
use Schema;
use ZipArchive;

use BuiltForm;
use BuiltModule;
use FormCategory;
use Module;

class ModuleInstaller {

    private $config;

    function __construct()
    {
        $this->modules_path = app_path() . '/Modules/';
        $this->temp_path = temp_path() . '/';
    }

    /**
     * Install the module
     * @param $file
     * @throws \Exception
     * @return array $input
     */
    public function installModule($file)
    {
        $filename = $this->uploadModule($file);

        $filename = str_replace('.ZIP', '.zip', $filename);
        $canonical = str_replace('.zip', '', $filename);

        $unzipSuccess = $this->Unzip("{$this->temp_path}{$filename}", "{$this->temp_path}{$canonical}");
        if (!$unzipSuccess) {
            throw new Exception("The module file {$filename} couldn\'t be extracted.");
        }

        if (!File::exists("{$this->temp_path}{$canonical}/module.json")) {
            throw new Exception('module.json doesn\'t exist in the module');
        }

        $this->config = json_decode(file_get_contents("{$this->temp_path}{$canonical}/module.json"), true);

        $replace_existing = (bool)Input::get('replace_existing');
        if (Module::where('alias', '=', $this->config['info']['alias'])->first()
            && !$replace_existing
        ) {
            throw new Exception('Another module with the same name already exists');
        }

        // Copy modules from temporary folder to modules folder
        $this->copyModule($canonical);

        File::delete($file);

        $this->manageTables();

        $form_ids = $this->addToBuiltForms();

        if ($form_ids) {
            $this->addToBuiltModules($form_ids);
        }

        $input = $this->fixInput();

        return $input;
    }

    /**
     * Upload the module to server
     * @param $file
     * @throws \Exception
     * @return array
     */
    public function uploadModule($file)
    {
        $filename = $file->getClientOriginalName();
        $extension = $file->getClientOriginalExtension();

        if ($extension == '') {
            $filename = $filename . '.zip';
        }

        $full_filename = $this->temp_path . $filename;
        File::exists($full_filename) && File::delete($full_filename);

        // Upload the module zip file to temporary folder
        $uploadSuccess = $file->move($this->temp_path, $filename);
//        if (!isset($uploadSuccess->fileName)) {
//            throw new Exception('The file couldn\'t be uploaded.');
//        }

        return $filename;
    }

    /**
     * Copy the module from temporary folder to modules
     * @param
     */
    public function copyModule($canonical)
    {
        $temp_module_dir = "{$this->temp_path}{$canonical}";

        File::copyDirectory("{$temp_module_dir}/{$this->config['info']['alias']}",
            "{$this->modules_path}{$this->config['info']['alias']}/");
        File::copy("{$temp_module_dir}/module.json",
            "{$this->modules_path}{$this->config['info']['alias']}/module.json");

        File::deleteDirectory($temp_module_dir);
    }

    /**
     * Fix the input to store in DB
     * @param
     * @return array
     */
    public function fixInput()
    {
        if (isset($this->config['forms'])) {
            // Get only the table names from the forms
            $table_names = array_pluck($this->config['forms'], 'table');
            $table = implode('|', $table_names);
        } else {
            $table = '';
        }

        $links = (isset($this->config['links'])) ? json_encode($this->config['links']) : '';

        $input = array(
            'name'       => $this->config['info']['name'],
            'alias'      => $this->config['info']['alias'],
            'hash'       => $this->config['info']['hash'],
            'version'    => $this->config['info']['version'],
            'author'     => $this->config['info']['author'],
            'website'    => $this->config['info']['website'],
            'target'     => $this->config['target'],
            'links'      => $links,
            'table'      => $table,
            'migrations' => $this->config['migrations'],
            'enabled'    => true
        );

        return $input;
    }

    /**
     * Mange table(s) in DB (CREATE/ALTER)
     * @param
     */
    private function manageTables()
    {
        if (isset($this->config['forms'])) {
            foreach ($this->config['forms'] as $form) {
                if (!Schema::hasTable("mdl_{$form['table']}")) {
                    $this->createTables($form);
                } else {
                    $this->alterTables($form);
                }
            }
        }

        $this->dbMigrate();
    }

    /**
     * Create new table(s) in DB
     * @param $form
     * @internal param
     */
    public function createTables($form)
    {
        $create_sql = "CREATE TABLE IF NOT EXISTS `mdl_{$form['table']}`";
        $create_sql .= "(`id` int(10) unsigned NOT NULL AUTO_INCREMENT, ";
        foreach ($form['fields'] as $field) {
            $create_sql .= "`$field` text COLLATE utf8_unicode_ci NULL, ";
        }
        $create_sql .= "`created_at` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00', `updated_at` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00', PRIMARY KEY (`id`)) ENGINE=InnoDB  DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci AUTO_INCREMENT=1 ;";
        DB::statement($create_sql);
    }

    /**
     * Alter existing table(s) in DB
     * @param $form
     */
    public function alterTables($form)
    {
        $alter_sql = "ALTER TABLE mdl_{$form['table']} ";
        $add_columns = array();
        $previous_field = 'id';
        foreach ($form['fields'] as $field) {
            if (!Schema::hasColumn("mdl_{$form['table']}", $field)) {
                $add_columns[] = "ADD COLUMN `{$field}` text COLLATE utf8_unicode_ci NULL AFTER `{$previous_field}`";
            }
            $previous_field = $field;
        }
        $alter_sql .= implode(', ', $add_columns) . ';';
        DB::unprepared($alter_sql);
    }

    public function dbMigrate()
    {
        $this->runMigrations();

        $this->storeMigrationData();

        $this->seedDatabase();
    }

    public function runMigrations()
    {
        $directory = $this->config['info']['alias'];

        $migrations_dir = 'app/Modules/' . $directory . '/Database/Migrations';

        Artisan::call('migrate', ['--path' => $migrations_dir]);
    }

    public function storeMigrationData()
    {
        $directory = $this->config['info']['alias'];
        $migrations_dir = app_path('Modules/' . $directory . '/Database/Migrations');
        $migration_files = [];

        foreach (File::files($migrations_dir) as $file) {
            $migration_files[] = pathinfo($file)['filename'];
        }

        $this->config['migrations'] = json_encode($migration_files);
    }

    private function seedDatabase()
    {
        $directory = $this->config['info']['alias'];
        $seed_dir = app_path('Modules/' . $directory . '/Database/Seeds');

        foreach (File::files($seed_dir) as $file) {
            require_once($file);
        }
        $seeder = new \DatabaseSeeder;
        $seeder->run();
    }

    /**
     * Get the information about the forms used in the module
     * and save/update their entries in the database, so that
     * they can be edited later(if required).
     */
    public function addToBuiltForms()
    {
        $forms = ['forms'];
        $form_ids = array();

        foreach ($forms as $form) {
            if (!isset($form['data'])) {
                return false;
            }
            $existing_form = BuiltForm::whereNotNull('hash')
                                    ->where('hash', $form['hash'])
                                    ->first();

            $existing_form_category = FormCategory::where('name', $form['category'])->first();
            if ($existing_form_category) {
                $form_category = $existing_form_category->id;
            } else {
                $category = FormCategory::create(array(
                        'name' => $form['category']
                    ));
                $form_category = $category->id;
            }

            $form_data = array(
                    'name'         => $form['form_name'],
                    'hash'         => $form['hash'],
                    'description'  => $form['description'],
                    'category'     => $form_category,
                    'show_captcha' => $form['show_captcha'],
                    'data'         => $form['data'],
                    'rendered'     => $form['rendered'],
                    'extra_code'   => $form['extra_code'],
                    'redirect_to'  => $form['redirect_to'],
                    'email'        => $form['email']
                );

            if ($existing_form) {
                $existing_form->update($form_data);
                $form_ids[] = $existing_form->id;
            } else {
                $form_id = BuiltForm::create($form_data);
                $form_ids[] = $form_id['id'];
            }
        }

        return $form_ids;
    }

    /**
     * Get the information about the module
     * and save/update their entries in the database, so that
     * they can be edited later(if required).
     * @param $module_info
     */
    public function addToBuiltModules($module, $form_ids)
    {
        $existing_module = BuiltModule::whereNotNull('hash')
                                ->where('hash', $module['info']['hash'])
                                ->first();

        $table_names = array_pluck($module['forms'], 'table');
        $table_name = implode('|', $table_names);

        $module_info = array(
                'name'        => $module['info']['name'],
                'hash'        => $module['info']['hash'],
                'alias'       => $module['info']['alias'],
                'version'     => $module['info']['version'],
                'author'      => $module['info']['author'],
                'website'     => $module['info']['website'],
                'description' => $module['info']['description'],
                'form_id'     => implode(', ', $form_ids),
                'target'      => $module['target'],
                'table_name'  => $table_name,
            );

        if ($existing_module) {
            $existing_module->update($module_info);
        } else {
            // To indicate that the module is not created in this system
            $module_info['is_author'] = false;
            BuiltModule::create($module_info);
        }
    }

    /**
     * Unzip the module file
     * @param $file
     * @param $path
     * @return bool
     */
    public function Unzip($file, $path)
    {
        // if(!is_file($file) || !is_readable($path)) {
        //     return Redirect::to('backend/modules')
        //                         ->with('error_message', "Can't read input file");
        // }

        // if(!is_dir($path) || !is_writable($path)) {
        //     return Redirect::to('backend/modules')
        //                         ->with('error_message', "Can't write to target");
        // }

        $zip = new ZipArchive;
        $res = $zip->open($file);
        if ($res === true) {
            // extract it to the path we determined above
            try {
                $zip->extractTo($path);
            } catch (ErrorException $e) {
                //skip
            }
            $zip->close();

            return true;
        } else {
            return false;
        }
    }
}
