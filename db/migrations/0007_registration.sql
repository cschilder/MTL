-- =============================================================================
-- Self-registration with approval.
--
-- A visitor may ask for an account (when the site has registration switched
-- on); the request lands as status 'pending' and cannot sign in until an
-- administrator approves it. 'invited' keeps meaning the opposite direction:
-- an admin created the account and the person still has to claim it.
-- =============================================================================

ALTER TABLE `{{prefix}}users`
    MODIFY `status` ENUM('invited','pending','active','disabled') NOT NULL DEFAULT 'invited';
