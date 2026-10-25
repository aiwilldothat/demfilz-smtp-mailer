<?php
/**
 * The PHPMailer subclass that routes finished messages.
 *
 * Loaded only when PHPMailer itself is in memory, because it extends it.
 *
 * @package Demfilz_SMTP_Mailer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'DSMTP_Mail_Catcher' ) ) {

	if ( class_exists( 'WP_PHPMailer' ) ) {
		class_alias( 'WP_PHPMailer', 'DSMTP_PHPMailer_Base' );
	} else {
		class_alias( 'PHPMailer\PHPMailer\PHPMailer', 'DSMTP_PHPMailer_Base' );
	}

	/**
	 * Takes the message WordPress has finished building and delivers it.
	 *
	 * WordPress fills this object exactly as it fills the standard one, so every
	 * header, attachment and inline image is already in place by the time
	 * postSend() runs. SMTP mailers hand back to PHPMailer, API mailers take the
	 * assembled message instead.
	 */
	class DSMTP_Mail_Catcher extends DSMTP_PHPMailer_Base {

		/**
		 * Mailer that actually delivered the message.
		 *
		 * @var string
		 */
		public $dsmtp_mailer = '';

		/**
		 * Message id reported by an API mailer.
		 *
		 * @var string
		 */
		public $dsmtp_message_id = '';

		/**
		 * Error from the primary mailer when the backup took over.
		 *
		 * @var string
		 */
		public $dsmtp_failover = '';

		/**
		 * Deliver the message.
		 *
		 * @return bool
		 * @throws PHPMailer\PHPMailer\Exception When delivery fails.
		 */
		public function postSend() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Overrides a PHPMailer method.
			$provider = DSMTP_Providers::active();

			$this->dsmtp_failover = '';
			$this->dsmtp_mailer   = $provider::id();

			try {
				$sent = $this->dsmtp_attempt( $provider, false );

				DSMTP_Mailer::record_success( $this );

				return $sent;
			} catch ( Exception $primary ) {
				$backup = DSMTP_Providers::backup();

				if ( $backup && $backup->is_configured() ) {
					try {
						$this->dsmtp_reset_transport();

						$this->dsmtp_failover = $primary->getMessage();
						$this->dsmtp_mailer   = $backup::id();

						$sent = $this->dsmtp_attempt( $backup, true );

						DSMTP_Mailer::record_success( $this );

						return $sent;
					} catch ( Exception $secondary ) {
						$this->dsmtp_mailer   = $provider::id();
						$this->dsmtp_failover = '';

						throw new PHPMailer\PHPMailer\Exception(
							sprintf(
								/* translators: 1: primary error, 2: backup mailer name, 3: backup error. */
								esc_html__( '%1$s The backup mailer %2$s also failed: %3$s', 'demfilz-smtp-mailer' ),
								esc_html( $primary->getMessage() ),
								esc_html( $backup::label() ),
								esc_html( $secondary->getMessage() )
							)
						);
					}
				}

				throw $primary;
			}
		}

		/**
		 * One delivery attempt with one mailer.
		 *
		 * @param DSMTP_Provider $provider  Mailer to use.
		 * @param bool           $configure Whether PHPMailer still needs configuring for it.
		 * @return bool
		 * @throws PHPMailer\PHPMailer\Exception When the mailer reports a problem.
		 */
		private function dsmtp_attempt( $provider, $configure ) {
			if ( DSMTP_Provider::TYPE_API === $provider::type() ) {
				$result = $provider->send( $this );

				if ( is_wp_error( $result ) ) {
					throw new PHPMailer\PHPMailer\Exception( esc_html( $result->get_error_message() ) );
				}

				$this->dsmtp_message_id = $provider->last_message_id();

				return true;
			}

			if ( $configure ) {
				$provider->configure( $this );
			}

			return parent::postSend();
		}

		/**
		 * Close any open connection before handing over to the backup mailer.
		 *
		 * @return void
		 */
		private function dsmtp_reset_transport() {
			if ( 'smtp' === $this->Mailer ) {
				$this->smtpClose();
			}

			/*
			 * Clearing the authentication type lets PHPMailer negotiate again with
			 * whatever the backup server offers, rather than insisting on the
			 * token based method the primary mailer asked for.
			 */
			$this->AuthType = '';
		}
	}
}
