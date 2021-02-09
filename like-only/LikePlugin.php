<?php

namespace RJPlugins;

use Gdn_Plugin;
use Gdn;
use Gdn_Model;
use Garden\Web\Exception\ClientException;
use \Vanilla\Exception\PermissionException;

class LikePlugin extends Gdn_Plugin {
    /** @var string The "Like" text */
    public $likeText = 'Like';
    /** @var string The "Like" text */
    public $unlikeText = 'Unlike';
    /** @var string The url of the plugin endpoint */
    public $pluginUrl = '';
    /** @var boolean Whether user can use the feature */
    public $canAdd = false;
    /** @var boolean Whether user can see likes */
    public $canView = false;

    /**
     *  Run on startup to init sane config settings and db changes.
     *
     *  @return void.
     */
    public function setup() {
        $this->structure();
    }

    /**
     *  Create tables and/or new columns.
     *
     *  @return void.
     */
    public function structure() {
        Gdn::structure()->table('UserComment')
            ->column('RJ_Like', 'tinyint', null, 'index')
            ->set();
        Gdn::structure()->table('UserDiscussion')
            ->column('RJ_Like', 'tinyint', null, 'index')
            ->set();
    }

    /**
     * Insert some CSS, init important variables only once per page call.
     *
     * @return void.
     */
    public function discussionController_beforeDiscussionDisplay_handler($sender, $args) {
        echo '<style>.Reactions a.HasCount{display: inline-block}.Reactions .Count{border-radius:3px;margin-right:4px;vertical-align:top}</style>';

        $this->init();
        // Prefetch all posts that I like.
        $px = Gdn::database()->DatabasePrefix;
        $userID = Gdn::session()->UserID;
        $sql = <<< EOT
            SELECT CommentID
            FROM {$px}UserComment
            WHERE CommentID IN (
              SELECT CommentID
              FROM {$px}Comment
              WHERE DiscussionID = {$args['Discussion']->DiscussionID}
            )
            AND UserID = {$userID}
            AND RJ_Like = true
        EOT;
        $likedComments = Gdn::sql()->query($sql)->resultArray();
        $this->iLiked = [
            'Discussion' => [$args['Discussion']->DiscussionID],
            'Comment' => array_column($likedComments, 'CommentID')
        ];
    }

    /**
     * Init variables need for creating the reaction buttons.
     *
     * @return void.
     */
    public function init() {
        $this->likeText = Gdn::translate('Like');
        $this->unlikeText = Gdn::translate('Unlike', 'You like that');
        $this->pluginUrl = Gdn::request()->url('/plugin/rjlike');
        $this->canAdd = Gdn::session()->checkPermission('Plugins.RJLike.Add');
        $this->canView = Gdn::session()->checkPermission('Plugins.RJLike.View');
    }

    /**
     * Insert "Like" below discussions and comments.
     *
     * @param DiscussionController $sender Instance of the calling class.
     * @param array $args Event arguments.
     *
     * @return string HTML of a reaction button.
     */
    public function discussionController_afterReactions_handler($sender, $args) {
        // Permissioncheck.
        if ($this->canAdd == false && $this->canView == false) {
            return;
        }
        // Only works for Comments and Discussions.
        if (array_key_exists('Comment', $args)) {
            $postType = 'Comment';
            $postID = $args['Comment']->CommentID;
         } elseif (array_key_exists('Discussion', $args)) {
            $postType = 'Discussion';
            $postID = $args['Discussion']->DiscussionID;
        } else {
            return;
        }
        $model = new Gdn_Model('User'.$postType);
        $likeCount = $model->getCount([
            $postType.'ID' => $postID,
            'RJ_Like' => true
        ]);
        echo $this->getButton(
            $postType,
            $postID,
            $likeCount,
            in_array($postID, $this->iLiked[$postType])
        );
    }

    /**
     * Return html markup for the Like button.
     *
     * @param string $postType Comment or Discussion
     * @param int $postID The ID of the Comment/Discussion.
     * @param int $count Number of likes for this post.
     * @param bool $liked Whether the user likes that post.
     *
     * @return string The markup of the Like button.
     */
    public function getButton(string $postType, int $postID, int $count, bool $liked = false) {
        if ($count > 0) {
            // Show number of likes...
            $cssClass = ' HasCount';
            $countString = '<span class="Count">'.$count.'</span>';
        } else {
            // ... or don't show any count at all.
            $cssClass = '';
            $countString = '';
        }
        // Disable link if needed.
        if ($this->canAdd) {
            $disabled = '';
        } else {
            $disabled = 'disabled ';
        }
        if ($liked) {
            $title = $this->unlikeText;
        } else {
            $title = $this->likeText;
        }
        // Build link.
        $htmlOut = "<a class=\"Hijack ReactButton ReactButton-Like{$cssClass}\" ";
        $htmlOut .= 'href="'.$this->pluginUrl.'/'.strtolower($postType).'/'.$postID.'" ';
        $htmlOut .= $disabled.'title="'.$title.'" rel="nofollow">';
        $htmlOut .= '<span class="ReactSprite ReactLike"></span>';
        $htmlOut .= $countString;
        $htmlOut .= '<span class="ReactLabel">'.$this->likeText.'</span>';
        $htmlOut .= '</a>';

        return $htmlOut;
    }

    /**
     * Endpoint which toggles likes.
     *
     * @param PluginController $sender Instance of the calling class.
     * @param mixed $args Event arguments.
     *
     * @return void.
     */
    public function pluginController_rjLike_create($sender, $args) {
        // Ensure POST for security reasons.
        if (!Gdn::request()->isAuthenticatedPostBack()) {
            throw new ClientException('Requires POST', 405);
        }
        // Init helper class variables.
        $this->init();
        // Do Permission check.
        if ($this->canAdd == false) {
            throw new PermissionException('Plugins.RJLike.Add');
        }
        // Only works for discussions and comments.
        $postType = ucfirst($args[0] ?? '');
        if (!in_array($postType, ['Comment', 'Discussion'])) {
            throw new Exception('Wrong Type');
        }
        $postID = intval($args[1] ?? 0);
        $userID = Gdn::session()->UserID;
        $model = new Gdn_Model('User'.$postType);
        $userData = $model->getWhere([
            'UserID' => $userID,
            $postType.'ID' => $postID
        ])->firstRow(DATASET_TYPE_ARRAY);

        $newStatus = true;

        if (!$userData) {
            // No row for that user/post combination, create one!
            $model->insert([
                'UserID' => $userID,
                $postType.'ID' => $postID,
                'RJ_Like' => $newStatus
            ]);
        } else {
            // Toggle like status.
            $newStatus = !$userData['RJ_Like'];
            $model->update(
                ['RJ_Like' => $newStatus],
                [
                    'UserID' => $userID,
                    $postType.'ID' => $postID
                ]
            );
        }

        // Return new button markup updated with count.
        $likeCount = $model->getCount([$postType.'ID' => $postID, 'RJ_Like' => true]);
        $sender->jsonTarget(
            "#{$postType}_{$postID} a.ReactButton-Like",
            $this->getButton($postType, $postID, $likeCount, (bool)$newStatus),
            'ReplaceWith'
        );
        $sender->render('Blank', 'Utility', 'Dashboard');
    }
}
