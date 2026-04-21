<?php
if (!defined('ABSPATH')) exit;

/**
 * CPP Livechat AI
 * Simple pattern-matching AI responder for the BriefSync live chat widget.
 * Matches visitor messages against keyword patterns and returns relevant responses.
 * Custom patterns can be configured via WordPress options (cpb_livechat_ai_patterns).
 */
if (class_exists('CPP_Livechat_AI')) return;
class CPP_Livechat_AI {

    /** @var array Compiled pattern list (merged defaults + custom) */
    protected $patterns = array();

    /** @var array Keywords that signal the visitor wants a human agent */
    protected $human_keywords = array('speak to', 'human', 'real person', 'agent', 'talk to someone', 'talk to a person', 'live agent', 'representative');

    public function __construct() {
        $this->patterns = $this->load_patterns();
    }

    /**
     * Generate a response for the AJAX handler.
     * Wraps respond() + needs_human() and adds conversation-count-based handoff.
     *
     * @param string $message  The visitor's message.
     * @param array  $history  Full conversation history from DB.
     * @param object $conversation  The conversation row.
     * @return array ['reply' => string, 'needs_human' => bool]
     */
    public function generate_response(string $message, array $history = array(), $conversation = null): array {
        $last_ai = '';
        $visitor_count = 0;
        foreach ($history as $row) {
            if (isset($row->sender_type)) {
                if ($row->sender_type === 'visitor') $visitor_count++;
                if ($row->sender_type === 'ai') $last_ai = $row->message;
            }
        }

        $wants_human = $this->needs_human($message);
        $reply = $this->respond($message, array('last_response' => $last_ai));

        // After 3 visitor messages, suggest human handoff
        if ($visitor_count >= 3 && !$wants_human) {
            $now_est = new \DateTime('now', new \DateTimeZone('America/New_York'));
            $hour = (int) $now_est->format('G');
            $day  = (int) $now_est->format('N'); // 1=Mon, 7=Sun

            if ($day <= 5 && $hour >= 9 && $hour < 17) {
                $reply .= "\n\nWould you like to speak with a team member? We're online right now!";
            } else {
                $reply .= "\n\nWould you like to leave a message for our team? We're available Mon-Fri 9 AM - 5 PM EST and will get back to you ASAP.";
            }
        }

        if ($wants_human) {
            $now_est = new \DateTime('now', new \DateTimeZone('America/New_York'));
            $hour = (int) $now_est->format('G');
            $day  = (int) $now_est->format('N');

            if ($day <= 5 && $hour >= 9 && $hour < 17) {
                $reply = "I'll connect you with a team member right away. They'll be with you shortly!";
            } else {
                $reply = "Our team is currently offline (we're available Mon-Fri, 9 AM - 5 PM EST). Your message has been saved and someone will get back to you as soon as possible!";
            }
        }

        return array(
            'reply'       => $reply,
            'needs_human' => $wants_human,
        );
    }

    /**
     * Generate a response for the given visitor message.
     *
     * @param string $message The visitor's message.
     * @param array  $context Optional context, e.g. ['last_response' => '...'] to avoid repeats.
     * @return string The AI response text.
     */
    public function respond(string $message, array $context = array()): string {
        $normalized = strtolower(trim($message));

        if ($normalized === '') {
            return $this->fallback();
        }

        // Walk through patterns in priority order and collect all matches.
        $matched = array();
        foreach ($this->patterns as $pattern) {
            foreach ($pattern['keywords'] as $keyword) {
                if (strpos($normalized, strtolower($keyword)) !== false) {
                    $matched[] = $pattern['response'];
                    break; // One match per pattern group is enough.
                }
            }
        }

        if (empty($matched)) {
            return $this->fallback();
        }

        $last_response = isset($context['last_response']) ? $context['last_response'] : '';

        // Pick the first match that is not the same as the previous response.
        foreach ($matched as $response) {
            if ($response !== $last_response) {
                return $response;
            }
        }

        // All matches equal the last response — return fallback instead.
        return $this->fallback();
    }

    /**
     * Check whether the visitor message indicates they want to talk to a human.
     *
     * @param string $message The visitor's message.
     * @return bool True if a human handoff should be triggered.
     */
    public function needs_human(string $message): bool {
        $normalized = strtolower(trim($message));

        foreach ($this->human_keywords as $keyword) {
            if (strpos($normalized, $keyword) !== false) {
                return true;
            }
        }

        return false;
    }

    // ------------------------------------------------------------------
    // Internal helpers
    // ------------------------------------------------------------------

    /**
     * Load and merge default patterns with any custom patterns saved in WP options.
     *
     * @return array Merged pattern list. Each element: ['keywords' => [...], 'response' => '...']
     */
    protected function load_patterns(): array {
        $defaults = $this->default_patterns();

        $custom_json = get_option('cpb_livechat_ai_patterns', '[]');
        $custom      = json_decode($custom_json, true);

        if (!is_array($custom) || empty($custom)) {
            return $defaults;
        }

        // Index defaults by a slug derived from the first keyword for easy override.
        $indexed = array();
        foreach ($defaults as $pattern) {
            $slug = $this->pattern_slug($pattern);
            $indexed[$slug] = $pattern;
        }

        // Custom patterns override defaults when they share a slug; otherwise they are appended.
        foreach ($custom as $pattern) {
            if (!isset($pattern['keywords']) || !isset($pattern['response'])) {
                continue;
            }
            $slug = $this->pattern_slug($pattern);
            $indexed[$slug] = $pattern;
        }

        return array_values($indexed);
    }

    /**
     * Generate a simple slug from a pattern's first keyword.
     *
     * @param array $pattern
     * @return string
     */
    protected function pattern_slug(array $pattern): string {
        $first = isset($pattern['keywords'][0]) ? $pattern['keywords'][0] : '';
        return sanitize_title($first);
    }

    /**
     * Built-in default patterns.
     *
     * @return array
     */
    protected function default_patterns(): array {
        return array(
            array(
                'keywords' => array('hi', 'hello', 'hey', 'good morning', 'good afternoon', 'good evening'),
                'response' => 'Hello! Welcome to BriefSync. How can I help you today?',
            ),
            array(
                'keywords' => array('price', 'pricing', 'cost', 'how much', 'plans'),
                'response' => 'We offer 4 plans ranging from $20 to $150/mo depending on your needs. You can view the full breakdown at /pricing — want me to help you pick the right one?',
            ),
            array(
                'keywords' => array('service', 'website', 'hosting', 'what do you do'),
                'response' => 'BriefSync provides managed website hosting paired with on-demand developer assistance. We handle hosting, maintenance, updates, and custom changes so you can focus on your business. Check out /add-ons for the full list of services.',
            ),
            array(
                'keywords' => array('speak to', 'human', 'real person', 'agent', 'talk to someone'),
                'response' => "I'll connect you with a team member right away. They'll be with you shortly!",
            ),
            array(
                'keywords' => array('hours', 'open', 'available', 'when'),
                'response' => 'Our team is generally available Monday–Friday, 9 AM – 6 PM EST. You can always leave a message and we\'ll get back to you as soon as possible.',
            ),
            array(
                'keywords' => array('how does', 'process', 'get started'),
                'response' => 'Getting started is easy: pick a plan, tell us about your project, and we take it from there. You can learn more about the process at /web-assist.',
            ),
            array(
                'keywords' => array('help', 'issue', 'problem', 'bug', 'support'),
                'response' => "I'm sorry you're having an issue. Could you describe what's happening? I can also connect you with our support team.",
            ),
            array(
                'keywords' => array('bye', 'goodbye', 'thanks', 'thank you'),
                'response' => 'Thanks for chatting with us! Feel free to reach out anytime.',
            ),
        );
    }

    /**
     * Return the fallback response when no pattern matches.
     *
     * @return string
     */
    protected function fallback(): string {
        return 'I can help with questions about our pricing, services, and support. Would you like me to connect you with a team member?';
    }
}
