<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'bws_settings' );
foreach ( array( 'me', 'taxes', 'countries', 'currencies', 'units', 'stocks', 'stock_places', 'accounts', 'contact_groups', 'languages' ) as $key ) {
	delete_transient( 'bws_lookup_' . $key );
}
// Verknüpfungen (_bws_article_id, _bws_order_id, _bws_contact_id) bleiben bewusst erhalten,
// damit bei einer Neuinstallation keine Duplikate in bexio entstehen.
