<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <title>{{ config('app.name', 'Laravel') }}</title>

  <!-- Fonts -->
  <link rel="preconnect" href="https://fonts.bunny.net">
  <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />

  <!-- Shopify App -->
  <!-- Polaris imports -->
  <meta name="shopify-api-key" content="{{ config('shopify-app.api_key') }}" />
  <script src="https://cdn.shopify.com/shopifycloud/app-bridge.js"></script>
  <script src="https://cdn.shopify.com/shopifycloud/polaris.js"></script>
  <script src="https://unpkg.com/vue@3/dist/vue.global.js"></script>


  <script>

  </script>
</head>

<body>
  <!-- === -->
  <!-- Home page pattern -->
  <!-- === -->
  <s-page id="app">
    <!-- === -->
    <!-- Callout Card -->
    <!-- If dismissed, use local storage or a database entry to avoid showing this section again to the same user. -->
    <!-- === -->
    <s-section id="main">
      <s-box style="max-width:50%; margin:0 auto;">

        <s-grid gridTemplateColumns="1fr auto" gap="small-400" alignItems="start">
          <s-grid gridTemplateColumns="@container (inline-size <= 480px) 1fr, auto auto" gap="base" alignItems="center">
            <s-grid gap="small-100">
              <s-heading>Get your store discovered by AI chatbots</s-heading>

              <s-paragraph>
                @{{ message }}
              </s-paragraph>
              <s-stack direction="inline" gap="small-200">
                <s-button variant="primary" :loading="isLoading" @click="generate()">
                  @{{ llmGenerated ? 'Regenerate' : 'Generate' }}
                </s-button>
                <s-button v-if="llmGenerated" variant="neutral" @click="previewLlms()">Preview LLMs.txt</s-button>
              </s-stack>
            </s-grid>

            <s-box maxInlineSize="400px" borderRadius="base" overflow="hidden">
              <s-image src="/images/boostsales.jpg" alt="Customize checkout illustration" aspectRatio="1/0.5"></s-image>
            </s-box>
          </s-grid>
        </s-grid>

      </s-box>
    </s-section>
    <s-section v-if="llmGenerated">
      <s-box>
        <s-heading>Your store is now discoverable by AI chatbots</s-heading>
        <s-text>Your LLMs.txt file has the following content:</s-text>
        <div>@{{ shop.products_count }} products</div>
        <div>@{{ shop.collections_count }} collections</div>
        <div>@{{ shop.pages_count }} pages</div>
        <div>@{{ shop.blogs_count }} blogs</div>
      </s-box>
    </s-section>
    <s-box slot="aside">
      <s-section heading="Why llms.txt">
        <s-paragraph>llms.txt is a file that helps AI chatbots discover your products, collections, pages, and blogs.
          Setting it up can help boost traffic to your store and increase sales.</s-paragraph>
      </s-section>
    </s-box>
  </s-page>
</body>

<script type="text/javascript">
  const { createApp, ref } = Vue

  createApp({
    data() {
      return {
        state: 'init',
        loadingStore: true,
        llmGenerated: false,
        shop: null,
      }
    },
    computed: {
        isLoading() {
            return this.loadingStore || this.state == 'loading'
        },
        message() {
            if (this.loadingStore) {
                return 'Loading your store status...';
            } else if (this.state == 'loading') {
                return 'Generating LLMs.txt which will help chat bots discover your products.';
            } else if (this.state == 'error') {
                return 'Something went wrong while generating. Please try again later';
            } else if (this.llmGenerated) {
                return 'Your LLMs.txt file has been generated! ChatGPT and other chat tools can now discover your products.';
            } else {
                return 'Start by generating your LLMs.txt file that helps chat bots discover your website and products.';
            }
        }
    },
    methods: {
        async loadStoreStatus() {
            try {
                const response = await fetch('/api/store', {
                    method: 'GET',
                    headers: {
                        'Accept': 'application/json',
                    },
                });

                if (!response.ok) {
                    throw new Error('Request failed');
                }

                const result = await response.json();
                this.llmGenerated = !!result.llm_generated;
                this.shop = result.shop;
            } catch (error) {
                console.error('Failed to load store status', error);
            } finally {
                this.loadingStore = false;
            }
        },
        async generate() {
            this.state = 'loading';

            try {
                const response = await fetch('/api/generate', {
                    method: 'GET',
                    headers: {
                        'Accept': 'application/json',
                    },
                });

                if (!response.ok) {
                    throw new Error('Request failed');
                }

                const result = await response.json();

                // Show success message
                if (result.success) {
                  this.llmGenerated = true;
                  this.state = 'generated';
                  this.loadStoreStatus();
                } else {
                  throw new Error(result.message || 'Request failed');
                }
            } catch (error) {
                console.error(error);
                this.state = 'error';
            }
        },
        previewLlms() {
            window.open('https://' + this.shop.name + '/llms.txt', '_blank');
        },
        learnMore() {
            window.open('https://llmstxt.org/', '_blank');
        }
    },
    mounted() {
        this.loadStoreStatus();
    }
  }).mount('#app')
</script>

</html>