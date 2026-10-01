<?php

namespace App\Enums;

/**
 * Kind of interaction recorded on a profile. Stored as a string and protected by a CHECK constraint.
 */
enum ProfileEventType: string
{
    case ProfileView = 'profile_view';
    case PhoneClick = 'phone_click';
    case WhatsappClick = 'whatsapp_click';
    case ShareClick = 'share_click';
    case DirectionClick = 'direction_click';
    case FavoriteAdd = 'favorite_add';
}
