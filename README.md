📦 ZeroGit (The Naked VCS) + 🤖 Bobbycar Agent

A single-file PHP cloud & Python CLI for solo developers. Zero dependencies. Zero Git. Zero Setup-Hell.

ZeroGit is a brutally pragmatic Version Control System designed for developers who just want to push code to a cheap shared web-hosting space without dealing with SSH keys, Git hooks, merge conflicts, or complex CI/CD pipelines.

If you ever thought: "I just want to ZIP this folder and send it to my server to have a backup and a history", ZeroGit is exactly that—but automated, highly optimized, secured against network-snooping, and wrapped in a beautiful Web UI.

NEW in v2.0: Includes the Bobbycar Sidecar Agent (zg_agent.py), a drop-in autonomous AI coder powered by Google's Gemma 4, that reads your project, writes code, and pushes commits—all completely client-side.

✨ Core Features

Zero Dependencies: The CLI uses pure Python standard libraries (no pip install). The backend is a single PHP file using PDO MySQL.

Single-File Cloud: Drop zerogit.php on your server, and you instantly get a private GitHub-like web interface.

Token-Based Auth: Your real password never travels over the network after the initial web-login. The CLI uses secure, rotatable API tokens.

Collision Detection (Atomic): If you edit code in the Web UI and try to push from your terminal later, ZeroGit safely blocks the push to prevent blind overwrites (unless you use --force).

Smart Globbing: The ignore list supports powerful glob patterns (*.env, node_modules/**, *secret*) to keep your payloads light and secure.

1-Click Forks: Found a cool public repo on a ZeroGit instance? Fork it into your private workspace with a single click.

Auto-Pruning: Keeps your server clean. Automatically deletes old snapshots and keeps only the latest 50 commits per repository.

🚀 60-Second Setup

1. The Server (Backend)

Create a blank MySQL database on your web host.

Upload zerogit.php to your web server (e.g., https://yourdomain.com/zerogit.php).

Open the URL in your browser. You will be greeted by the Initial Setup Screen. Create your admin account.

Security Lock: The script will automatically generate a zg_env.php file containing your database credentials. Move this file outside your public webroot if possible and update the path in zerogit.php (Line 36).

Click Create Repository and note down the Repo ID.

Copy your API Token from the top right corner of the web interface (🔑).

2. The Local Machine (Client)

Drop the zg.py file into the root of your local project folder. Open your terminal and link it to your new cloud:

Initialize the connection:

$ python zg.py init https://yourdomain.com/zerogit.php your_username <YOUR_API_TOKEN> <REPO_ID>


Save and push your first snapshot:

$ python zg.py save "Initial Commit"


🤖 Meet Bobbycar (The Gemma 4 Agent)

ZeroGit comes with a built-in AI Sidecar (zg_agent.py). It uses Google's Gemma 4 Open Weights via the Gemini API to autonomously code in your project. It acts as a client, meaning your backend needs zero AI-bloat.

Drop zg_agent.py next to your zg.py.

Run an agent command:

$ python zg_agent.py "Refactor the layout to use Tailwind CSS"


The Agent will:

Read your entire local code context.

Talk to the Gemma 4 API.

Rewrite/Create the necessary local files.

Show you a colorized Diff of what it changed.

Ask for your confirmation (j/N).

Automatically commit and push the result to your ZeroGit server!

(Note: On first run, it will ask for a free Google AI Studio API key).

🛠️ CLI Commands (zg.py)

python zg.py init - Links the current folder to your ZeroGit server.

python zg.py save "Message" - Zips your folder, pushes it, and registers a commit.

python zg.py save "Msg" --force - Force-pushes, ignoring server-side Web-UI edits.

python zg.py diff - Shows local changes compared to the latest server commit.

python zg.py history - Displays the timeline of your snapshots.

python zg.py undo <ID> - Safely downloads a snapshot, verifies it in a temp-folder (Extract-then-Swap), and overwrites your local state.

⚙️ Configuration & Security

When you run init, ZeroGit creates a hidden .zg_config.json file. You can edit this file to add wildcard patterns that should never be uploaded.

{
    "url": "https://yourdomain.com/zerogit.php",
    "ignore_list": [
        ".zg_config.json",
        "zg_env.php",
        "*.env",
        "*secret*",
        "node_modules"
    ]
}


Note: ZeroGit automatically patches critical secrets (*.env, *.pem) into your ignore list to prevent accidental leaks to the server or the AI Agent.

🛡️ Enterprise-Grade Pragmatism

Zip-Slip Protection: Both the Web-Editor and the Python Client strictly sanitize zip-entry paths, preventing directory traversal attacks during extraction.

Anti-Directory-Listing: Snapshots are saved as .pack files with SHA-256 randomized names and a protective index.php. Safe on Nginx and Apache alike.

Atomic Pushes: Database commits use SELECT ... FOR UPDATE row-level locking. No race conditions if two people push at the exact same millisecond.

ZeroGit - Built for Pragmatists. E > H.
