<?php

namespace PeopleInside\RestrictedVisibility;

final class Constants
{
    public const ID = 'peopleinside-restricted-visibility';
    public const PERM_RESTRICT = 'peopleinside-restricted-visibility.restrict';
    public const PERM_VIEW_ALL = 'peopleinside-restricted-visibility.viewAll';
    public const CONFIRM_WORD = 'ELIMINA';
    public const MAX_GROUPS = 50;
    public const MAX_USERS = 100;
    public const MODES = ['hide', 'delete', 'public'];
    public const SETTING_BUTTON_POSITION = 'peopleinside-restricted-visibility.composer_button_position';
    public const BUTTON_POSITIONS = ['before_submit', 'after_submit', 'after_preview'];
    public const BUTTON_POSITION_DEFAULT = 'after_submit';
}
