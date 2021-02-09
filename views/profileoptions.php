<?php if (!defined('APPLICATION')) exit();
/** Displays the "Edit My Profile" or "Back to Profile" buttons on the top of the profile page. */
?>
<div class="ProfileOptions">
    <?php
    if (Gdn::controller()->EditMode)  {
        echo anchor(t('Back to Post'), userUrl(Gdn::controller()->User), ['class' => 'PostButtons']);
    } else {
        echo buttonGroup($this->data('MemberOptions'), 'NavButton MemberButtons').' ';
        echo $this->data('LikeButton');
        echo $this->data('ProfileOptionsDropdown');
    }
    ?>
</div>
<script>
[].forEach.call(document.querySelectorAll('.ProfileOptions a.NavButton'), function(el) {
    if (el.getAttribute('href').indexOf('/plugin/like/') != -1) {
        if (!el.classList.contains('Hijack')) {
            el.classList.add('Hijack');
        }
    }
});
</script>
