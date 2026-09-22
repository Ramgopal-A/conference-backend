require('dotenv').config();
const express = require('express');
const http = require('http'); 
const { Server } = require('socket.io');
const cors = require('cors');
const { Pool } = require('pg');
const bcrypt = require('bcrypt');
const jwt = require('jsonwebtoken');

const app = express();
// Create the HTTP server wrapping your Express app
const server = http.createServer(app);


// PostgreSQL connection setup(local setup)



/*
require('dotenv').config();
const { Pool } = require('pg');

// Setup for local PostgreSQL connection
const pool = new Pool({
    user: process.env.DB_USER,
    password: process.env.DB_PASSWORD,
    host: process.env.DB_HOST,
    port: process.env.DB_PORT,
    database: process.env.DB_NAME,
});

// Test the connection
pool.connect((err, client, release) => {
    if (err) {
        return console.error('Error acquiring client', err.stack);
    }
    console.log('Successfully connected to local PostgreSQL database!');
    release();
});

module.exports = pool;
*/




// Initialize Socket.io with CORS enabled so Flutter can connect
const io = new Server(server, {
    cors: {
        origin: "*", 
        methods: ["GET", "POST"]
    }
});
app.use(cors());
app.use(express.json());




//PostgreSQL connection setup(online neon setup)
const pool = new Pool({
    connectionString: process.env.DATABASE_URL,
});



// Socket.io Real-Time Chat Logic
io.on('connection', (socket) => {
    console.log(`🟢 New user connected via WebSockets: ${socket.id}`);

    // 1. User joins a specific private chat room
    socket.on('join_room', (conversationId) => {
        socket.join(conversationId);
        console.log(`👤 User joined conversation room: ${conversationId}`);
    });

    // 2. User sends a message
    socket.on('send_message', async (data) => {
        // We expect Flutter to send us these three pieces of info
        const { conversationId, senderId, messageText } = data;

        try {
            // A. Save the message to the Neon database permanently
            const insertQuery = `
                INSERT INTO messages (conversation_id, sender_id, message) 
                VALUES ($1, $2, $3) RETURNING *;
            `;
            const savedMessage = await pool.query(insertQuery, [conversationId, senderId, messageText]);

            // B. Instantly broadcast the saved message to the other person in the room
            io.to(conversationId).emit('receive_message', savedMessage.rows[0]);
            
        } catch (err) {
            console.error("❌ Error saving live message:", err);
        }
    });

    socket.on('disconnect', () => {
        console.log(`🔴 User disconnected: ${socket.id}`);
    });
});

// Fetch all past messages for a specific conversation
app.get('/api/conversations/:conversationId/messages', async (req, res) => {
    try {
        const { conversationId } = req.params;
        
        const historyQuery = `
            SELECT * FROM messages 
            WHERE conversation_id = $1 
            ORDER BY created_at ASC;
        `;
        const messages = await pool.query(historyQuery, [conversationId]);
        
        res.status(200).json(messages.rows);
    } catch (err) {
        console.error(err);
        res.status(500).json({ error: "Failed to fetch message history" });
    }
});

const JWT_SECRET = process.env.JWT_SECRET || 'super_secret_conference_key_2026';

// ==========================================
// SECURITY MIDDLEWARE: Check the VIP Wristband (JWT)
// ==========================================
const authenticateToken = (req, res, next) => {
    // 1. Look for the Authorization header sent by Flutter
    const authHeader = req.headers['authorization'];
    const token = authHeader && authHeader.split(' ')[1]; // Format is "Bearer <token>"

    if (!token) {
        return res.status(401).json({ error: 'Access denied. No token provided.' });
    }

    // 2. Verify the token uses our secret key
    jwt.verify(token, JWT_SECRET, (err, user) => {
        if (err) return res.status(403).json({ error: 'Invalid or expired token.' });
        
        // 3. Attach the decrypted user data (which contains userId) to the request!
        req.user = user; 
        next(); // Let them pass to the database route
    });
};



// ==========================================
// 0. HEALTH CHECK (To test Wi-Fi connection)
// ==========================================
app.get('/api/test', (req, res) => {
    console.log("⚡ Flutter App Connected!");
    res.json({ message: "Backend is online!" });
});

// ==========================================
// 1. REGISTER (SIGN UP) ENDPOINT
// ==========================================
app.post('/api/register', async (req, res) => {
    // 1. Extract using 'phone' instead of 'phone_no'
    const { 
        full_name, 
        email, 
        password, 
        confirm_password, 
        phone, 
        organization, 
        designation, 
        country 
    } = req.body;

    try {
        if (password !== confirm_password) {
            return res.status(400).json({ error: 'Passwords do not match!' });
        }

        const userCheck = await pool.query('SELECT * FROM users WHERE email = $1', [email]);
        if (userCheck.rows.length > 0) {
            return res.status(400).json({ error: 'User already exists with this email' });
        }

        const salt = await bcrypt.genSalt(10);
        const passwordHash = await bcrypt.hash(password, salt);

        // 2. Insert using 'phone' to match your database column perfectly
        const newUser = await pool.query(
            `INSERT INTO users (email, password_hash, full_name, phone, organization, designation, country) 
             VALUES ($1, $2, $3, $4, $5, $6, $7) RETURNING id, email, full_name`,
            [email, passwordHash, full_name, phone, organization, designation, country]
        );

        const token = jwt.sign({ userId: newUser.rows[0].id }, JWT_SECRET, { expiresIn: '7d' });
        
        console.log(`✅ New user registered with full details: ${email}`);
        res.status(201).json({ token, user: newUser.rows[0] });

    } catch (err) {
        console.error("Backend Error:", err.message);
        res.status(500).json({ error: 'Registration failed' });
    }
});

// ==========================================
// 2.GET ALL VENUES ENDPOINT
// ==========================================
app.get('/api/venues', async (req, res) => {
    try {
        // Fetch all columns and rows from the venues table
        const result = await pool.query('SELECT * FROM venues');
        
        // Send the data back to the frontend
        res.status(200).json(result.rows);
    } catch (err) {
        console.error("Backend Error fetching venues:", err.message);
        res.status(500).json({ error: 'Failed to retrieve venues data' });
    }
});


// ==========================================
// 3. LOGIN ENDPOINT
// ==========================================
app.post('/api/login', async (req, res) => {
    const { email, password } = req.body;
    try {
        console.log(`🔐 Login attempt for: ${email}`);
        
        const userResult = await pool.query('SELECT * FROM users WHERE email = $1', [email]);
        if (userResult.rows.length === 0) {
            return res.status(401).json({ error: 'Invalid email or password' });
        }

        const user = userResult.rows[0];
        const isMatch = await bcrypt.compare(password, user.password_hash);
        
        if (!isMatch) {
            return res.status(401).json({ error: 'Invalid email or password' });
        }

        const token = jwt.sign({ userId: user.id }, JWT_SECRET, { expiresIn: '7d' });
        delete user.password_hash; 
        
        console.log(`✅ Login successful for: ${email}`);
        res.json({ token, user });
    } catch (err) {
        res.status(500).json({ error: 'Login failed' });
    }
});

// ==========================================
// 4. GET LOGGED-IN USER DETAILS (For the Pass Screen)
// ==========================================
app.get('/api/user/me', authenticateToken, async (req, res) => {
    try {
        // Because of the middleware, we now know exactly who this is!
        // req.user.userId was securely decoded from their token.
        const userId = req.user.userId;

        console.log(`🎫 Fetching digital pass details for User ID: ${userId}`);

        // Fetch ONLY this user from the database. 
        // We explicitly list columns so we NEVER accidentally send the password_hash!
        const userResult = await pool.query(
            `SELECT id, email, full_name, phone, organization, designation, country, role 
             FROM users WHERE id = $1`,
            [userId]
        );

        if (userResult.rows.length === 0) {
            return res.status(404).json({ error: 'User not found in database.' });
        }

        // Send the secure user profile back to Flutter
        res.json(userResult.rows[0]);

    } catch (err) {
        console.error("Pass Screen Error:", err.message);
        res.status(500).json({ error: 'Failed to fetch user details' });
    }
});

// ==========================================
// 5. START OR FETCH A 1-ON-1 CONVERSATION
// ==========================================
app.post('/api/conversations', authenticateToken, async (req, res) => {
    const userId = req.user.userId; // The logged-in user
    const { receiverId } = req.body; // The person they want to chat with

    try {
        // 1. Check if a chat room already exists between these two exact users
        const checkQuery = `
            SELECT c.id 
            FROM conversations c
            JOIN conversation_members m1 ON c.id = m1.conversation_id
            JOIN conversation_members m2 ON c.id = m2.conversation_id
            WHERE m1.user_id = $1 AND m2.user_id = $2;
        `;
        const existingChat = await pool.query(checkQuery, [userId, receiverId]);

        if (existingChat.rows.length > 0) {
            // Chat exists! Return the existing room ID so Flutter can connect
            return res.json({ conversation_id: existingChat.rows[0].id });
        }

        // 2. No chat exists yet. Create a brand new conversation room.
        const newConv = await pool.query(`INSERT INTO conversations DEFAULT VALUES RETURNING id`);
        const conversationId = newConv.rows[0].id;

        // 3. Add both users as members of this new room
        const addMembersQuery = `
            INSERT INTO conversation_members (conversation_id, user_id) 
            VALUES ($1, $2), ($1, $3);
        `;
        await pool.query(addMembersQuery, [conversationId, userId, receiverId]);

        res.status(201).json({ conversation_id: conversationId });
    } catch (err) {
        console.error(err);
        res.status(500).json({ error: 'Failed to start conversation' });
    }
});



// ==========================================
// 6. GET USER'S INBOX (List of all active chats)
// ==========================================
app.get('/api/conversations', authenticateToken, async (req, res) => {
    const userId = req.user.userId;

    try {
        // Find all rooms the user is in, and grab the name/designation of the OTHER person in that room
        const inboxQuery = `
            SELECT 
                c.id AS conversation_id,
                u.id AS other_user_id,
                u.full_name AS other_user_name,
                u.designation
            FROM conversations c
            JOIN conversation_members cm ON c.id = cm.conversation_id
            JOIN users u ON cm.user_id = u.id
            WHERE c.id IN (
                SELECT conversation_id FROM conversation_members WHERE user_id = $1
            )
            AND u.id != $1; -- Ensure we don't return the logged-in user's own details
        `;
        const inbox = await pool.query(inboxQuery, [userId]);
        
        res.json(inbox.rows);
    } catch (err) {
        console.error(err);
        res.status(500).json({ error: 'Failed to fetch inbox' });
    }
});


// ==========================================
// GET THEMES (Hierarchical: Theme -> Subtheme -> Content)
// ==========================================
app.get('/api/themes', async (req, res) => {
    try {
        // Fetch all three layers
        const themesData = await pool.query('SELECT * FROM tbl_theme');
        const subthemesData = await pool.query('SELECT * FROM tbl_theme_subtheme ORDER BY display_order ASC');
        const contentsData = await pool.query('SELECT * FROM tbl_theme_subtheme_content ORDER BY display_order ASC');

        // Nest the data using JavaScript
        const themes = themesData.rows.map(theme => {
            return {
                theme_id: theme.theme_id,
                theme_name: theme.theme_name,
                theme_desc: theme.theme_desc,
                subthemes: subthemesData.rows
                    .filter(sub => sub.theme_id === theme.theme_id)
                    .map(sub => {
                        return {
                            subtheme_id: sub.subtheme_id,
                            sub_theme_name: sub.sub_theme_name,
                            contents: contentsData.rows.filter(content => content.subtheme_id === sub.subtheme_id)
                        };
                    })
            };
        });

        res.json(themes);
    } catch (err) {
        console.error("Themes Error:", err.message);
        res.status(500).json({ error: 'Failed to fetch themes' });
    }
});


// ==========================================
// GET KEYNOTE SPEAKERS
// ==========================================
app.get('/api/speakers', async (req, res) => {
    try {
        const speakers = await pool.query('SELECT * FROM keynote_speakers ORDER BY name ASC');
        res.json(speakers.rows);
    } catch (err) {
        console.error("Speakers Error:", err.message);
        res.status(500).json({ error: 'Failed to fetch speakers' });
    }
});

// ==========================================
// GET COMMITTEES
// ==========================================
app.get('/api/committees', async (req, res) => {
    try {
        const committees = await pool.query('SELECT * FROM committees ORDER BY display_order ASC');
        res.json(committees.rows);
    } catch (err) {
        console.error("Committees Error:", err.message);
        res.status(500).json({ error: 'Failed to fetch committees' });
    }
});

// ==========================================
// GET ACCOMMODATIONS (Hotels & Student)
// ==========================================
app.get('/api/accommodations', async (req, res) => {
    try {
        const hotels = await pool.query('SELECT * FROM accommodations ORDER BY created_at ASC');
        const student = await pool.query('SELECT * FROM student_accommodations');
        
        // Group them into one response
        res.json({
            hotels: hotels.rows,
            student_accommodation: student.rows[0] || null // Since there's only one student record
        });
    } catch (err) {
        console.error("Accommodations Error:", err.message);
        res.status(500).json({ error: 'Failed to fetch accommodations' });
    }
});

// ==========================================
// GET VENUE FACILITIES (Image Grid)
// ==========================================
app.get('/api/venues/facilities', async (req, res) => {
    try {
        const facilities = await pool.query('SELECT * FROM venue_facilities ORDER BY display_order ASC');
        res.json(facilities.rows);
    } catch (err) {
        console.error("Venue Facilities Error:", err.message);
        res.status(500).json({ error: 'Failed to fetch venue facilities' });
    }
});





const PORT = process.env.PORT || 3000;
server.listen(PORT, () => console.log(`🚀 API running on port ${PORT}`));