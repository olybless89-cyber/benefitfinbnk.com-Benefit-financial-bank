<?php

namespace App\Helpers;

use App\Models\Notification;
use App\Models\User;

class NotificationHelper
{
    /**
     * Create a new notification for a user.
     *
     * @param  User|int  $user  The user or user ID
     * @param  string  $message  Notification message
     * @param  string|null  $title  Notification title
     * @param  string  $type  Notification type (info, success, warning, danger)
     * @param  string|null  $icon  Lucide icon name
     * @param  string|null  $link  URL to redirect when clicked
     * @param  array|null  $data  Additional data
     * @return Notification
     */
    public static function create($user, $message, $title = null, $type = 'info', $icon = null, $link = null, $data = null)
    {
        $userId = $user instanceof User ? $user->id : $user;
        
        // Set default icon based on type if not provided
        if ($icon === null) {
            $icon = self::getDefaultIconForType($type);
        }
        
        return Notification::create([
            'user_id' => $userId,
            'title' => $title,
            'message' => $message,
            'type' => $type,
            'icon' => $icon,
            'link' => $link,
            'data' => $data,
            'is_read' => false,
        ]);
    }
    
    /**
     * Get the default icon for a notification type.
     *
     * @param  string  $type
     * @return string
     */
    private static function getDefaultIconForType($type)
    {
        switch ($type) {
            case 'success':
                return 'check-circle';
            case 'warning':
                return 'alert-triangle';
            case 'danger':
                return 'alert-circle';
            case 'info':
            default:
                return 'info';
        }
    }
} 