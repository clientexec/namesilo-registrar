<?php

require_once 'modules/admin/models/RegistrarPlugin.php';

class PluginNamesilo extends RegistrarPlugin
{
    public function getVariables()
    {
        $variables = array(
            lang('Plugin Name') => array (
                                'type'          =>'hidden',
                                'description'   =>lang('How CE sees this plugin (not to be confused with the Signup Name)'),
                                'value'         =>lang('NameSilo')
                               ),
            lang('Use testing server') => array(
                                'type'          =>'yesno',
                                'description'   =>lang('Select Yes if you wish to use NameSilo\'s testing environment, so that transactions are not actually made.'),
                                'value'         =>0
                               ),
            lang('API Key') => array(
                                'type'          =>'text',
                                'description'   =>lang('Enter your API Key'),
                                'value'         =>''
                               ),
            lang('Supported Features')  => array(
                                'type'          => 'label',
                                'description'   => '',
                                'value'         => ''
                                ),
            lang('Actions') => array (
                                'type'          => 'hidden',
                                'description'   => lang('Current actions that are active for this plugin (when a domain isn\'t registered)'),
                                'value'         => 'Register'
                                ),
            lang('Registered Actions') => array (
                                'type'          => 'hidden',
                                'description'   => lang('Current actions that are active for this plugin (when a domain is registered)'),
                                'value'         => '',
                                ),
            lang('Registered Actions For Customer') => array (
                                'type'          => 'hidden',
                                'description'   => lang('Current actions that are active for this plugin (when a domain is registered)'),
                                'value'         => '',
            )
        );
        return $variables;
    }  
}