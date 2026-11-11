<?php

namespace PeopleInside\RestrictedVisibility;

final class Constants
{
    public const ID = 'peopleinside-restricted-visibility';
    /** Creare NUOVI contenuti privati (nuova discussione o nuova risposta con visibilità limitata). */
    public const PERM_CREATE_PRIVATE = 'peopleinside-restricted-visibility.createPrivate';
    /** Rendere privato un contenuto ESISTENTE (o cambiarne i destinatari). Nome nel database invariato: ".restrict". */
    public const PERM_MAKE_PRIVATE = 'peopleinside-restricted-visibility.restrict';
    /** Rendere pubblico (rimuovere la restrizione). */
    public const PERM_MAKE_PUBLIC = 'peopleinside-restricted-visibility.makePublic';
    public const PERM_VIEW_ALL = 'peopleinside-restricted-visibility.viewAll';
    public const CONFIRM_WORD = 'DELETE';
    public const MAX_GROUPS = 50;
    public const MAX_USERS = 100;
    public const MODES = ['hide', 'delete', 'public'];
    public const SETTING_BUTTON_POSITION = 'peopleinside-restricted-visibility.composer_button_position';
    public const BUTTON_POSITIONS = ['before_submit', 'after_submit', 'after_preview'];
    public const BUTTON_POSITION_DEFAULT = 'after_submit';
}
