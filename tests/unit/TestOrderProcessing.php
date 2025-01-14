
    <?php
    class TestOrderProcessing extends WP_UnitTestCase {
      private $plugin;

      public function setUp() {
        parent::setUp();
        $this->plugin = new WooCommerceCiviCRMIntegration();
      }

      public function test_order_data_extraction() {
        $order = $this->create_mock_order();
        $data = $this->plugin->extract_order_data($order);

        $this->assertArrayHasKey('email', $data);
        $this->assertArrayHasKey('total', $data);
        $this->assertArrayHasKey('status', $data);
      }

      public function test_contribution_creation() {
        $order_data = [
          'email' => 'test@example.com',
          'total' => 100.00,
          'status' => 'completed'
        ];

        $contribution_data = $this->plugin->