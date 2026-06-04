CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL
);

CREATE TABLE products (
    product_id INT AUTO_INCREMENT PRIMARY KEY,
    seller_id INT NOT NULL,
    minimum_starting_price DECIMAL(10,2) NOT NULL,
    buyout_price DECIMAL(10, 2),
    description TEXT,
    start_time TIMESTAMP NOT NULL,
    end_time TIMESTAMP NOT NULL,
    
    CHECK (end_time > start_time),
    FOREIGN KEY (seller_id) REFERENCES users(id)
);

CREATE TABLE bid (
    bid_id INT AUTO_INCREMENT PRIMARY KEY,
    bidder_id INT NOT NULL,
    product_id INT NOT NULL,
    bid_amount DECIMAL(10, 2) NOT NULL,
    bid_timestamp TIMESTAMP NOT NULL,

    FOREIGN KEY (bidder_id) REFERENCES users(id),
    FOREIGN KEY (product_id) REFERENCES products(product_id)
);

CREATE TABLE sale (
    sale_id INT AUTO_INCREMENT PRIMARY KEY,
    buyer_id INT NOT NULL,
    product_id INT NOT NULL,
    final_price DECIMAL(10, 2) NOT NULL,

    FOREIGN KEY (buyer_id) REFERENCES users(id),
    FOREIGN KEY (product_id) REFERENCES products(product_id)
);

